<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use App\Services\IncomingEmail\IncomingEmailServiceCaseCreateService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailAutoCreateBranchCTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.auto_create_service_case' => false,
            'inbound_email.ignored_labels' => [
                'SPAM',
                'TRASH',
                'CATEGORY_PROMOTIONS',
                'CATEGORY_SOCIAL',
            ],
            'inbound_email.system_sender_patterns' => [
                'mailer-daemon@',
                'noreply@',
                'no-reply@',
            ],
            'inbound_email.system_from_names' => [
                'mail delivery subsystem',
                'mailer-daemon',
            ],
            'inbound_email.auto_responder_header_tokens' => [
                'auto-submitted',
                'list-unsubscribe',
            ],
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
            ],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $dayAdmin = $this->createAdminUser('day-branch-c@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-branch-c@test.com', 'Night Admin');

        app(SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $nightAdmin->id,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
            'assignment.communication_intake_primary_user_id' => (string) $dayAdmin->id,
            'assignment.communication_intake_fallback_user_id' => (string) $nightAdmin->id,
        ]);
    }

    public function test_flag_off_keeps_needs_review_for_unknown_customer(): void
    {
        config(['inbound_email.auto_create_service_case' => false]);

        $beforeOrders = Order::query()->count();
        $beforeIncidents = Incident::query()->count();

        $message = $this->ingestEmail(fromEmail: 'unknown-off@example.com');

        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message?->status);
        $this->assertNull($message?->incident_id);
        $this->assertNull($message?->order_id);
        $this->assertSame($beforeOrders, Order::query()->count());
        $this->assertSame($beforeIncidents, Incident::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.needs_review',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_flag_on_creates_exactly_one_inq_order_and_one_service_case(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $beforeOrders = Order::query()->count();
        $beforeIncidents = Incident::query()->count();

        $message = $this->ingestEmail(
            fromEmail: 'new.customer@example.com',
            fromName: 'New Customer',
            subject: 'Need a device quote',
            preview: 'Looking to buy a scanner.',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertNotNull($message?->incident_id);
        $this->assertSame($beforeOrders + 1, Order::query()->count());
        $this->assertSame($beforeIncidents + 1, Incident::query()->count());
        $this->assertSame(1, IncidentIncomingEmailLink::query()->count());

        $incident = Incident::query()->findOrFail($message->incident_id);
        $order = $incident->order;

        $this->assertNotNull($order);
        $this->assertTrue(Order::isInquiryOrderId($order->order_id));
        $this->assertStringStartsWith('INQ-', $order->order_id);
        $this->assertSame('new.customer@example.com', $order->customer_email);
        $this->assertSame($order->id, $message->order_id);
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame('Sales Lead', $incident->category);
        $this->assertNotNull($incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_reprocessing_linked_unknown_email_creates_nothing_new(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $message = $this->ingestEmail(fromEmail: 'reprocess-c@example.com');
        $incidentId = $message?->incident_id;
        $orderId = $message?->order_id;

        $this->assertNotNull($incidentId);
        $this->assertNotNull($orderId);

        app(IncomingEmailProcessorService::class)->process($message->fresh());

        $this->assertSame(1, Order::query()->whereKey($orderId)->count());
        $this->assertSame(1, Incident::query()->whereKey($incidentId)->count());
        $this->assertSame(1, IncidentIncomingEmailLink::query()->where('incident_id', $incidentId)->count());
        $this->assertSame($incidentId, $message->fresh()->incident_id);
    }

    public function test_concurrent_unknown_emails_share_one_inq_and_one_service_case(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $first = $this->ingestEmail(
            fromEmail: 'concurrent-c@example.com',
            subject: 'First ask',
            rfcMessageId: '<first-c@radium.test>',
            providerMessageId: 'prov-c-1',
            threadId: 'thread-c-1',
        );
        $second = $this->ingestEmail(
            fromEmail: 'concurrent-c@example.com',
            subject: 'Second ask',
            rfcMessageId: '<second-c@radium.test>',
            providerMessageId: 'prov-c-2',
            threadId: 'thread-c-2',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $first?->status);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $second?->status);
        $this->assertNotNull($first?->incident_id);
        $this->assertSame($first->incident_id, $second?->incident_id);
        $this->assertSame($first->order_id, $second?->order_id);

        $this->assertSame(1, Order::query()->where('customer_email', 'concurrent-c@example.com')->count());
        $this->assertSame(1, Incident::query()->whereIn('status', IncidentStatus::operationallyActive())->count());
        $this->assertSame(2, IncidentIncomingEmailLink::query()->where('incident_id', $first->incident_id)->count());

        $order = Order::query()->findOrFail($first->order_id);
        $this->assertTrue(Order::isInquiryOrderId($order->order_id));
    }

    public function test_unmatched_support_mailbox_sender_follows_branch_c_when_flag_on(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $message = $this->ingestEmail(
            fromEmail: 'existing.but.unmatched@example.com',
            subject: 'Service question without prior order',
            mailbox: 'support@radiumbox.com',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $incident = Incident::query()->findOrFail($message->incident_id);
        $this->assertTrue(Order::isInquiryOrderId($incident->order->order_id));
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame('existing.but.unmatched@example.com', $incident->order->customer_email);
    }

    public function test_spam_and_system_remain_ignored_with_flag_on(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $spam = $this->ingestEmail(
            fromEmail: 'spammer@example.com',
            labels: ['SPAM'],
            rfcMessageId: '<spam-c@radium.test>',
            providerMessageId: 'prov-spam-c',
        );
        $system = $this->ingestEmail(
            fromEmail: 'noreply@newsletter.example.com',
            rfcMessageId: '<system-c@radium.test>',
            providerMessageId: 'prov-system-c',
        );

        $this->assertNull($spam);
        $this->assertSame(0, IncomingEmailMessage::query()->where('ignore_reason', 'spam')->count());
        $this->assertSame(IncomingEmailMessageStatus::Ignored, $system?->status);
        $this->assertSame(0, Incident::query()->count());
        $this->assertSame(0, Order::query()->where('order_id', 'like', 'INQ-%')->count());
    }

    public function test_promotions_remain_ignored_with_flag_on(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $message = $this->ingestEmail(
            fromEmail: 'deals@shop.example.com',
            labels: ['CATEGORY_PROMOTIONS'],
        );

        $this->assertNull($message);
        $this->assertSame(0, IncomingEmailMessage::query()->count());
        $this->assertSame(0, Incident::query()->count());
        $this->assertDatabaseHas('incoming_email_ignore_stats', [
            'reason' => 'promotions',
            'count' => 1,
        ]);
    }

    public function test_flag_default_remains_disabled(): void
    {
        $this->assertFalse(config('inbound_email.auto_create_service_case'));
        $this->assertFalse(app(IncomingEmailServiceCaseCreateService::class)->isEnabled());
    }

    private function ingestEmail(
        string $fromEmail,
        string $subject = 'Support request',
        string $preview = 'Please help.',
        string $rfcMessageId = '',
        string $providerMessageId = '',
        string $threadId = '',
        string $fromName = 'Customer',
        string $mailbox = 'support@radiumbox.com',
        array $labels = [],
        array $headers = [],
        int $attachmentCount = 0,
    ): ?IncomingEmailMessage {
        $rfcMessageId = $rfcMessageId !== '' ? $rfcMessageId : '<'.uniqid('rfc-', true).'@radium.test>';
        $providerMessageId = $providerMessageId !== '' ? $providerMessageId : 'prov-'.uniqid();
        $threadId = $threadId !== '' ? $threadId : 'thread-'.uniqid();

        $dto = new NormalizedInboundEmail(
            mailbox: $mailbox,
            provider: 'fixture',
            providerMessageId: $providerMessageId,
            rfcMessageId: $rfcMessageId,
            threadId: $threadId,
            fromEmail: $fromEmail,
            fromName: $fromName,
            toEmails: [$mailbox],
            subject: $subject,
            preview: $preview,
            receivedAt: now(),
            attachmentCount: $attachmentCount,
            headers: $headers,
            labels: $labels,
            rawPayload: ['fixture' => true],
        );

        return app(IncomingEmailIngestService::class)->ingest($dto);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        app(PresenceEngineService::class)->startSession($user);

        return $user;
    }
}
