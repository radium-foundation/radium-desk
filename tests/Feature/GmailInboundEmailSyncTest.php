<?php

namespace Tests\Feature;

use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\GmailMailboxSyncState;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\Gmail\GmailMessageMapper;
use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GmailInboundEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.gmail.enabled' => true,
            'inbound_email.gmail.sync_mailboxes' => ['support@radiumbox.com'],
            'inbound_email.gmail.api_base_url' => 'https://gmail.googleapis.com',
            'inbound_email.gmail.service_account_json' => '{"client_email":"sa@test.iam.gserviceaccount.com","private_key":"unused-in-tests"}',
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => false,
            'cache.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);

        $this->mock(GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    public function test_disabled_flags_do_not_sync(): void
    {
        config(['inbound_email.enabled' => false]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['mailboxes']);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
        $this->assertSame(0, GmailMailboxSyncState::query()->count());
    }

    public function test_first_sync_baselines_history_without_importing_mail(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'emailAddress' => 'support@radiumbox.com',
                'historyId' => '1000',
            ], 200),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(1, $result['mailboxes']);
        $this->assertSame(0, $result['pulled']);
        $this->assertSame(0, $result['ingested']);
        $this->assertSame(0, IncomingEmailMessage::query()->count());

        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
        ]);

        $state = GmailMailboxSyncState::query()->where('mailbox', 'support@radiumbox.com')->first();
        $this->assertNotNull($state?->baselined_at);
        $this->assertNotNull($state?->enabled_at);
    }

    public function test_incremental_sync_ingests_new_messages_via_existing_pipeline(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        $bodyText = 'The fingerprint sensor stopped working.';
        $encodedBody = rtrim(strtr(base64_encode($bodyText), '+/', '-_'), '=');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-1', 'threadId' => 'thr-1']],
                        ],
                    ],
                ],
                'historyId' => '1100',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-1*' => Http::response([
                'id' => 'msg-1',
                'threadId' => 'thr-1',
                'labelIds' => ['INBOX'],
                'snippet' => 'The fingerprint sensor',
                'internalDate' => (string) (now()->getTimestampMs()),
                'payload' => [
                    'mimeType' => 'text/plain',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Customer <customer@example.com>'],
                        ['name' => 'To', 'value' => 'support@radiumbox.com'],
                        ['name' => 'Subject', 'value' => 'Need help with my device'],
                        ['name' => 'Message-ID', 'value' => '<gmail-live-1@radium.test>'],
                    ],
                    'body' => [
                        'data' => $encodedBody,
                        'size' => strlen($bodyText),
                    ],
                ],
            ], 200),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(1, $result['pulled']);
        $this->assertSame(1, $result['ingested']);

        $message = IncomingEmailMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('gmail', $message->provider);
        $this->assertSame('msg-1', $message->provider_message_id);
        $this->assertSame('thr-1', $message->thread_id);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame($bodyText, $message->raw_payload['body_text'] ?? null);
        $this->assertSame('Need help with my device', $message->subject);

        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1100',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.received',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_duplicate_sync_is_idempotent(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        $payload = [
            'id' => 'msg-dup',
            'threadId' => 'thr-dup',
            'labelIds' => ['INBOX'],
            'internalDate' => (string) now()->getTimestampMs(),
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => 'customer@example.com'],
                    ['name' => 'To', 'value' => 'support@radiumbox.com'],
                    ['name' => 'Subject', 'value' => 'Dup'],
                    ['name' => 'Message-ID', 'value' => '<dup@radium.test>'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
            ],
        ];

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::sequence()
                ->push([
                    'history' => [
                        ['messagesAdded' => [['message' => ['id' => 'msg-dup']]]],
                    ],
                    'historyId' => '1100',
                ], 200)
                ->push([
                    'history' => [
                        ['messagesAdded' => [['message' => ['id' => 'msg-dup']]]],
                    ],
                    'historyId' => '1100',
                ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-dup*' => Http::response($payload, 200),
        ]);

        app(IncomingEmailGmailSyncService::class)->sync();

        // Reset cursor to simulate retry before commit / repeated history events.
        GmailMailboxSyncState::query()->where('mailbox', 'support@radiumbox.com')->update([
            'history_id' => '1000',
        ]);

        app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(1, IncomingEmailMessage::query()->count());
    }

    public function test_mapper_extracts_body_and_attachment_metadata(): void
    {
        $mapper = app(GmailMessageMapper::class);

        $dto = $mapper->toNormalized('support@radiumbox.com', [
            'id' => 'msg-map',
            'threadId' => 'thr-map',
            'labelIds' => ['INBOX', 'UNREAD'],
            'internalDate' => '1720000000000',
            'payload' => [
                'mimeType' => 'multipart/mixed',
                'headers' => [
                    ['name' => 'From', 'value' => 'Ada Lovelace <ada@example.com>'],
                    ['name' => 'To', 'value' => 'support@radiumbox.com'],
                    ['name' => 'Subject', 'value' => 'Attachment test'],
                    ['name' => 'Message-ID', 'value' => '<map@radium.test>'],
                ],
                'parts' => [
                    [
                        'mimeType' => 'text/plain',
                        'body' => ['data' => rtrim(strtr(base64_encode('Plain body'), '+/', '-_'), '=')],
                    ],
                    [
                        'mimeType' => 'text/html',
                        'body' => ['data' => rtrim(strtr(base64_encode('<p>Html body</p>'), '+/', '-_'), '=')],
                    ],
                    [
                        'filename' => 'photo.jpg',
                        'mimeType' => 'image/jpeg',
                        'body' => [
                            'attachmentId' => 'att-1',
                            'size' => 1234,
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame('ada@example.com', $dto->fromEmail);
        $this->assertSame('Ada Lovelace', $dto->fromName);
        $this->assertSame('Plain body', $dto->bodyText);
        $this->assertSame('<p>Html body</p>', $dto->bodyHtml);
        $this->assertSame(1, $dto->attachmentCount);
        $this->assertSame('photo.jpg', $dto->attachments[0]['filename'] ?? null);
        $this->assertSame('att-1', $dto->attachments[0]['attachment_id'] ?? null);
    }

    public function test_history_expired_rebaselines_without_import(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => 'old-history',
            'enabled_at' => now()->subDay(),
            'baselined_at' => now()->subDay(),
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'error' => ['message' => 'Start history id is too old'],
            ], 404),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response([
                'historyId' => '9999',
            ], 200),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['pulled']);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '9999',
        ]);
    }

    public function test_skips_mailbox_when_sync_lock_is_held(): void
    {
        Http::fake();

        $lock = Cache::lock('gmail-inbound-sync:'.sha1('support@radiumbox.com'), 120);
        $this->assertTrue($lock->get());

        try {
            $result = app(IncomingEmailGmailSyncService::class)->sync();

            $this->assertSame(1, $result['skipped']);
            $this->assertSame(0, $result['pulled']);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_ingests_oldest_message_first(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        $newerMs = (string) now()->getTimestampMs();
        $olderMs = (string) now()->subMinutes(5)->getTimestampMs();

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-new']],
                            ['message' => ['id' => 'msg-old']],
                        ],
                    ],
                ],
                'historyId' => '1200',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-new*' => Http::response(
                $this->gmailMessagePayload('msg-new', 'thr-new', '<new@radium.test>', $newerMs, 'Newer'),
                200,
            ),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-old*' => Http::response(
                $this->gmailMessagePayload('msg-old', 'thr-old', '<old@radium.test>', $olderMs, 'Older'),
                200,
            ),
        ]);

        app(IncomingEmailGmailSyncService::class)->sync();

        $ids = IncomingEmailMessage::query()->orderBy('id')->pluck('provider_message_id')->all();
        $this->assertSame(['msg-old', 'msg-new'], $ids);
    }

    public function test_missing_credentials_fail_with_clear_error(): void
    {
        config(['inbound_email.gmail.service_account_json' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GOOGLE_SERVICE_ACCOUNT_JSON is not configured');

        app(IncomingEmailGmailSyncService::class)->sync();
    }

    public function test_sync_log_excludes_body_contents(): void
    {
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = [
                'message' => (string) $message->message,
                'context' => $message->context,
            ];
        });

        $this->seedCustomerWithOpenIncident('customer@example.com');

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        $secretBody = 'SECRET_BODY_SHOULD_NOT_APPEAR_IN_LOGS';

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'msg-log']]]],
                ],
                'historyId' => '1100',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-log*' => Http::response(
                $this->gmailMessagePayload(
                    'msg-log',
                    'thr-log',
                    '<log@radium.test>',
                    (string) now()->getTimestampMs(),
                    'Subject',
                    $secretBody,
                ),
                200,
            ),
        ]);

        app(IncomingEmailGmailSyncService::class)->sync();

        $syncLog = collect($logged)->firstWhere('message', '[GmailInbound] Mailbox sync completed.');
        $this->assertNotNull($syncLog);
        $this->assertSame('support@radiumbox.com', $syncLog['context']['mailbox'] ?? null);
        $this->assertSame('1000', $syncLog['context']['previous_history_id'] ?? null);
        $this->assertSame('1100', $syncLog['context']['new_history_id'] ?? null);
        $this->assertSame(1, $syncLog['context']['messages_received'] ?? null);
        $this->assertArrayHasKey('linked', $syncLog['context']);
        $this->assertArrayHasKey('ignored', $syncLog['context']);
        $this->assertArrayHasKey('failed', $syncLog['context']);
        $this->assertArrayHasKey('elapsed_ms', $syncLog['context']);
        $this->assertStringNotContainsString($secretBody, json_encode($syncLog['context']) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    private function gmailMessagePayload(
        string $id,
        string $threadId,
        string $rfcMessageId,
        string $internalDateMs,
        string $subject,
        string $body = 'hello',
    ): array {
        return [
            'id' => $id,
            'threadId' => $threadId,
            'labelIds' => ['INBOX'],
            'internalDate' => $internalDateMs,
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => 'customer@example.com'],
                    ['name' => 'To', 'value' => 'support@radiumbox.com'],
                    ['name' => 'Subject', 'value' => $subject],
                    ['name' => 'Message-ID', 'value' => $rfcMessageId],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
            ],
        ];
    }

    private function seedCustomerWithOpenIncident(string $email): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-GMAIL-'.uniqid(),
            'serial_number' => 'SN-GMAIL-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Gmail Customer',
            'customer_phone' => '9876509999',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-GMAIL-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Existing open incident for gmail sync tests.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
