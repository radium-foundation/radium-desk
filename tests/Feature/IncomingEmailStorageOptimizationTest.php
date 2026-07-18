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
use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IncomingEmailStorageOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.preview_max_chars' => 500,
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

    public function test_gmail_sync_stores_preview_and_attachment_metadata_without_bodies(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        $bodyText = "First paragraph for preview.\n\nSecond paragraph is not stored.";
        $encodedBody = rtrim(strtr(base64_encode($bodyText), '+/', '-_'), '=');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'msg-storage', 'threadId' => 'thr-storage']]]],
                ],
                'historyId' => '1100',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-storage*' => Http::response([
                'id' => 'msg-storage',
                'threadId' => 'thr-storage',
                'labelIds' => ['INBOX'],
                'snippet' => 'First paragraph for preview.',
                'internalDate' => (string) (now()->getTimestampMs()),
                'payload' => [
                    'mimeType' => 'multipart/mixed',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Customer <customer@example.com>'],
                        ['name' => 'To', 'value' => 'support@radiumbox.com'],
                        ['name' => 'Subject', 'value' => 'Attachment test'],
                        ['name' => 'Message-ID', 'value' => '<storage-test@radium.test>'],
                    ],
                    'parts' => [
                        [
                            'mimeType' => 'text/plain',
                            'body' => [
                                'data' => $encodedBody,
                                'size' => strlen($bodyText),
                            ],
                        ],
                        [
                            'filename' => 'invoice.pdf',
                            'mimeType' => 'application/pdf',
                            'body' => [
                                'attachmentId' => 'att-1',
                                'size' => 2048,
                            ],
                        ],
                        [
                            'filename' => 'photo.png',
                            'mimeType' => 'image/png',
                            'body' => [
                                'attachmentId' => 'att-2',
                                'size' => 4096,
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        app(IncomingEmailGmailSyncService::class)->sync();

        $message = IncomingEmailMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame('First paragraph for preview.', $message->preview);
        $this->assertNull($message->raw_payload['body_text'] ?? null);
        $this->assertNull($message->raw_payload['body_html'] ?? null);
        $this->assertSame(2, $message->attachment_count);
        $this->assertCount(2, $message->attachmentMetadata());
        $this->assertSame('invoice.pdf', $message->attachmentMetadata()[0]['filename']);
        $this->assertSame('att-1', $message->attachmentMetadata()[0]['attachment_id']);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/attachments/'));
    }

    public function test_full_email_content_is_fetched_live_from_gmail(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-live',
            'thread_id' => 'thr-live',
            'from_email' => 'customer@example.com',
            'from_name' => 'Customer',
            'to_emails' => ['support@radiumbox.com'],
            'subject' => 'Live fetch test',
            'preview' => 'Preview only.',
            'received_at' => now(),
            'attachment_count' => 0,
            'headers' => [],
            'labels' => [],
            'raw_payload' => [],
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
        ]);

        $fullBody = "Preview paragraph.\n\nFull body paragraph two.";
        $encodedBody = rtrim(strtr(base64_encode($fullBody), '+/', '-_'), '=');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-live*' => Http::response([
                'id' => 'msg-live',
                'threadId' => 'thr-live',
                'internalDate' => (string) (now()->getTimestampMs()),
                'payload' => [
                    'mimeType' => 'text/plain',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Customer <customer@example.com>'],
                        ['name' => 'Subject', 'value' => 'Live fetch test'],
                    ],
                    'body' => ['data' => $encodedBody],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.incoming-email-messages.content', $message),
        );

        $response->assertOk()
            ->assertJsonPath('source', 'gmail')
            ->assertJsonPath('body_text', $fullBody)
            ->assertJsonPath('subject', 'Live fetch test');
    }

    public function test_attachment_download_fetches_binary_on_demand(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-att',
            'thread_id' => 'thr-att',
            'from_email' => 'customer@example.com',
            'from_name' => 'Customer',
            'to_emails' => ['support@radiumbox.com'],
            'subject' => 'Attachment download',
            'preview' => 'Preview only.',
            'received_at' => now(),
            'attachment_count' => 1,
            'headers' => [],
            'labels' => [],
            'raw_payload' => [
                'attachments' => [[
                    'attachment_id' => 'att-live',
                    'filename' => 'report.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 11,
                ]],
            ],
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
        ]);

        $binary = '%PDF-1.4 test';
        $encoded = rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-att/attachments/att-live*' => Http::response([
                'data' => $encoded,
            ], 200),
        ]);

        $response = $this->actingAs($agent)->get(
            route('dashboard.incoming-email-messages.attachments.download', [
                'incomingEmailMessage' => $message,
                'attachment' => 'att-live',
            ]),
        );

        $response->assertOk();
        $this->assertSame($binary, $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_legacy_stored_body_is_returned_without_gmail_call(): void
    {
        [$order, $incident] = $this->seedCustomerWithOpenIncident('customer@example.com');
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $message = IncomingEmailMessage::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'channel' => 'support',
            'provider' => 'gmail',
            'provider_message_id' => 'msg-legacy',
            'from_email' => 'customer@example.com',
            'to_emails' => ['support@radiumbox.com'],
            'subject' => 'Legacy stored email',
            'preview' => 'Legacy preview.',
            'received_at' => now(),
            'attachment_count' => 0,
            'headers' => [],
            'labels' => [],
            'raw_payload' => [
                'body_text' => 'Legacy full body stored in database.',
            ],
            'status' => IncomingEmailMessageStatus::Linked,
            'incident_id' => $incident->id,
            'order_id' => $order->id,
        ]);

        Http::fake();

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.incoming-email-messages.content', $message),
        );

        $response->assertOk()
            ->assertJsonPath('source', 'database')
            ->assertJsonPath('body_text', 'Legacy full body stored in database.');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedCustomerWithOpenIncident(string $email): array
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-STORAGE-'.uniqid(),
            'serial_number' => 'SN-STORAGE-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Storage Customer',
            'customer_phone' => '9876501111',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-STORAGE-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Storage optimization test incident.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }
}
