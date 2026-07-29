<?php

namespace Tests\Feature;

use App\Models\GmailMailboxSyncState;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use App\Services\IncomingEmail\Gmail\GmailAccessTokenService;
use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailSyncResilienceTest extends TestCase
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
            'inbound_email.gmail.http_retry_times' => 3,
            'inbound_email.gmail.http_retry_sleep_ms' => 1,
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

    public function test_transient_http_400_is_retried_then_succeeds(): void
    {
        $this->seedBaselinedState('1000');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'id' => '1100',
                        'messagesAdded' => [['message' => ['id' => 'msg-flaky']]],
                    ],
                ],
                'historyId' => '1200',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-flaky*' => Http::sequence()
                ->push(['error' => ['message' => 'Backend error']], 400)
                ->push($this->messagePayload('msg-flaky', 'Recovered subject'), 200),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['failed_mailboxes']);
        $this->assertSame(1, $result['pulled']);
        $this->assertGreaterThanOrEqual(1, $result['messages_retried']);
        $this->assertSame(1, IncomingEmailMessage::query()->count());
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1200',
        ]);
    }

    public function test_http_400_exhausted_is_skipped_and_sync_continues(): void
    {
        $this->seedBaselinedState('1000');

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'id' => '1100',
                        'messagesAdded' => [
                            ['message' => ['id' => 'msg-bad']],
                            ['message' => ['id' => 'msg-good']],
                        ],
                    ],
                ],
                'historyId' => '1200',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-bad*' => Http::response([
                'error' => ['message' => 'Invalid id value'],
            ], 400),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-good*' => Http::response(
                $this->messagePayload('msg-good', 'Good mail'),
                200,
            ),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['failed_mailboxes']);
        $this->assertSame(1, $result['messages_failed']);
        $this->assertSame(1, $result['pulled']);
        $this->assertSame('msg-good', IncomingEmailMessage::query()->value('provider_message_id'));
        $this->assertDatabaseHas('gmail_sync_message_failures', [
            'message_id' => 'msg-bad',
            'http_status' => 400,
        ]);
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'history_id' => '1200',
        ]);
    }

    public function test_incremental_cursor_commits_after_each_history_entry(): void
    {
        $this->seedBaselinedState('1000');

        $cursorsSeen = [];

        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$cursorsSeen) {
            if (str_contains($request->url(), '/history')) {
                return Http::response([
                    'history' => [
                        [
                            'id' => '1050',
                            'messagesAdded' => [['message' => ['id' => 'msg-1']]],
                        ],
                        [
                            'id' => '1100',
                            'messagesAdded' => [['message' => ['id' => 'msg-2']]],
                        ],
                    ],
                    'historyId' => '1200',
                ], 200);
            }

            if (str_contains($request->url(), '/messages/msg-2')) {
                $cursorsSeen[] = GmailMailboxSyncState::query()
                    ->where('mailbox', 'support@radiumbox.com')
                    ->value('history_id');
            }

            if (str_contains($request->url(), '/messages/')) {
                $id = str_contains($request->url(), 'msg-1') ? 'msg-1' : 'msg-2';

                return Http::response($this->messagePayload($id, $id), 200);
            }

            return Http::response(['error' => ['message' => 'unexpected']], 500);
        });

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['failed_mailboxes']);
        $this->assertSame(2, $result['pulled']);
        $this->assertContains('1050', $cursorsSeen);
        $this->assertGreaterThanOrEqual(2, $result['cursor_advances']);
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1200',
        ]);
    }

    public function test_backlog_recovers_after_previous_transient_failure(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subDay(),
            'baselined_at' => now()->subDay(),
            'last_synced_at' => now()->subDay(),
            'last_error' => 'Gmail API GET /gmail/v1/users/me/messages/old-bad failed: HTTP 400',
            'consecutive_failures' => 5,
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    [
                        'id' => '1100',
                        'messagesAdded' => [
                            ['message' => ['id' => 'old-bad']],
                            ['message' => ['id' => 'new-good']],
                        ],
                    ],
                ],
                'historyId' => '1300',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/old-bad*' => Http::response([
                'error' => ['message' => 'Invalid id value'],
            ], 400),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/new-good*' => Http::response(
                $this->messagePayload('new-good', 'Backlog recovered'),
                200,
            ),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(0, $result['failed_mailboxes']);
        $this->assertSame(1, $result['pulled']);
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1300',
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);
        $this->assertSame(1, IncomingEmailMessage::query()->count());
    }

    private function seedBaselinedState(string $historyId): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => $historyId,
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(string $id, string $subject): array
    {
        return [
            'id' => $id,
            'threadId' => 'thr-'.$id,
            'labelIds' => ['INBOX'],
            'internalDate' => (string) now()->getTimestampMs(),
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => 'customer@example.com'],
                    ['name' => 'To', 'value' => 'support@radiumbox.com'],
                    ['name' => 'Subject', 'value' => $subject],
                    ['name' => 'Message-ID', 'value' => '<'.$id.'@radium.test>'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('hello'), '+/', '-_'), '=')],
            ],
        ];
    }
}
