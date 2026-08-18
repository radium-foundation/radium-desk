<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailIntakeQueue;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\GmailMailboxSyncState;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailGmailSyncService;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailIntakeCounterService;
use App\Services\IncomingEmail\IncomingEmailOutboxWriter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IncomingEmailGmailLabelSkipTest extends TestCase
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
            'inbound_email.mailboxes' => ['support@radiumbox.com' => 'support'],
            'inbound_email.ignored_labels' => ['SPAM', 'TRASH', 'CATEGORY_PROMOTIONS', 'CATEGORY_SOCIAL'],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'cache.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ]);

        $this->mock(\App\Services\IncomingEmail\Gmail\GmailAccessTokenService::class, function ($mock): void {
            $mock->shouldReceive('tokenForMailbox')->andReturn('test-access-token');
        });
    }

    #[DataProvider('highConfidenceIgnoredLabelsProvider')]
    public function test_high_confidence_labels_skip_persistence_audit_and_outbox(array $labels, string $reason): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        $result = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'label-skip-'.uniqid(),
            rfcMessageId: '<label-skip-'.uniqid().'@radium.test>',
            threadId: 'thread-label-skip',
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            toEmails: ['support@radiumbox.com'],
            subject: 'Noise message',
            preview: 'Noise body',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: $labels,
            rawPayload: ['fixture' => true],
        ));

        $this->assertNull($result);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
        $this->assertSame(0, \App\Models\AuditLog::query()->count());
        $this->assertSame(0, \App\Models\OutboxEvent::query()->count());
        $this->assertDatabaseHas('incoming_email_ignore_stats', [
            'reason' => $reason,
            'count' => 1,
        ]);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function highConfidenceIgnoredLabelsProvider(): array
    {
        return [
            'spam' => [['SPAM'], 'spam'],
            'trash' => [['TRASH'], 'trash'],
            'promotions' => [['CATEGORY_PROMOTIONS'], 'promotions'],
            'social' => [['CATEGORY_SOCIAL'], 'social'],
        ];
    }

    public function test_inbox_customer_email_still_follows_existing_pipeline(): void
    {
        $this->seedCustomerWithOpenIncident('customer@example.com');

        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'inbox-customer-1',
            rfcMessageId: '<inbox-customer-1@radium.test>',
            threadId: 'thread-inbox-1',
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            toEmails: ['support@radiumbox.com'],
            subject: 'Need help with device',
            preview: 'Fingerprint sensor stopped working.',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: ['INBOX'],
            rawPayload: ['fixture' => true],
        ));

        $this->assertNotNull($message);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->fresh()?->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.received',
            'auditable_id' => $message->id,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => IncomingEmailOutboxWriter::EVENT_TYPE,
            'aggregate_id' => $message->id,
        ]);
    }

    public function test_gmail_sync_advances_history_when_spam_is_skipped_at_ingest(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1000',
            'enabled_at' => now()->subMinute(),
            'baselined_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [
                    ['messagesAdded' => [['message' => ['id' => 'msg-spam', 'threadId' => 'thr-spam']]]],
                ],
                'historyId' => '1100',
            ], 200),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/msg-spam*' => Http::response([
                'id' => 'msg-spam',
                'threadId' => 'thr-spam',
                'labelIds' => ['SPAM'],
                'snippet' => 'Buy now',
                'internalDate' => (string) now()->getTimestampMs(),
                'payload' => [
                    'mimeType' => 'text/plain',
                    'headers' => [
                        ['name' => 'From', 'value' => 'promo@example.com'],
                        ['name' => 'To', 'value' => 'support@radiumbox.com'],
                        ['name' => 'Subject', 'value' => 'Promo offer'],
                        ['name' => 'Message-ID', 'value' => '<gmail-spam-1@radium.test>'],
                    ],
                    'body' => ['data' => rtrim(strtr(base64_encode('Buy now'), '+/', '-_'), '=')],
                ],
            ], 200),
        ]);

        $result = app(IncomingEmailGmailSyncService::class)->sync();

        $this->assertSame(1, $result['pulled']);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
        $this->assertDatabaseHas('gmail_mailbox_sync_states', [
            'mailbox' => 'support@radiumbox.com',
            'history_id' => '1100',
        ]);
        $this->assertDatabaseHas('incoming_email_ignore_stats', [
            'reason' => 'spam',
            'count' => 1,
        ]);
    }

    public function test_spam_skip_increments_dashboard_counter_without_admin_row(): void
    {
        app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: 'support@radiumbox.com',
            provider: 'fixture',
            providerMessageId: 'spam-counter-1',
            rfcMessageId: '<spam-counter-1@radium.test>',
            threadId: 'thread-spam-counter',
            fromEmail: 'promo@example.com',
            fromName: 'Promo',
            toEmails: ['support@radiumbox.com'],
            subject: 'Promo',
            preview: 'Promo body',
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: ['SPAM'],
            rawPayload: ['fixture' => true],
        ));

        $this->assertSame(
            1,
            app(IncomingEmailIntakeCounterService::class)->counts()[IncomingEmailIntakeQueue::Spam->value],
        );
        $this->assertSame(0, IncomingEmailMessage::query()->count());
    }

    private function seedCustomerWithOpenIncident(string $email): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-LABEL-'.uniqid(),
            'serial_number' => 'SN-LABEL-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Label Skip Customer',
            'customer_phone' => '9876508888',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-LABEL-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Existing open incident for label skip tests.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
