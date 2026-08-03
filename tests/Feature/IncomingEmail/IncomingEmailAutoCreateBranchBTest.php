<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
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
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailAutoCreateBranchBTest extends TestCase
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

        $dayAdmin = $this->createAdminUser('day-branch-b@test.com', 'Day Admin');
        $nightAdmin = $this->createAdminUser('night-branch-b@test.com', 'Night Admin');

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

    public function test_flag_off_keeps_historical_association_for_order_without_active_sc(): void
    {
        config(['inbound_email.auto_create_service_case' => false]);

        $order = $this->seedCustomerOrder('historical@example.com');
        $before = Incident::query()->count();

        $message = $this->ingestEmail(fromEmail: 'historical@example.com');

        $this->assertSame(IncomingEmailMessageStatus::HistoricalCustomer, $message?->status);
        $this->assertSame($order->id, $message?->order_id);
        $this->assertNull($message?->incident_id);
        $this->assertSame($before, Incident::query()->count());
        $this->assertSame(0, IncidentIncomingEmailLink::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.historical_customer',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_flag_on_creates_one_service_case_links_and_routes(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $order = $this->seedCustomerOrder('create-sc@example.com');
        $before = Incident::query()->count();

        $message = $this->ingestEmail(
            fromEmail: 'create-sc@example.com',
            subject: 'Scanner stopped working',
            preview: 'Need service help please.',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertNotNull($message?->incident_id);
        $this->assertSame($order->id, $message?->order_id);
        $this->assertSame($before + 1, Incident::query()->count());
        $this->assertSame(1, IncidentIncomingEmailLink::query()->count());

        $incident = Incident::query()->findOrFail($message->incident_id);
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertSame($order->id, $incident->order_id);
        $this->assertTrue($incident->isActive());
        $this->assertNotNull($incident->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'incoming_email.historical_customer',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_reprocessing_linked_message_does_not_create_duplicate_sc(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $order = $this->seedCustomerOrder('reprocess@example.com');

        $message = $this->ingestEmail(fromEmail: 'reprocess@example.com');
        $incidentId = $message?->incident_id;

        $this->assertNotNull($incidentId);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);

        app(IncomingEmailProcessorService::class)->process($message->fresh());

        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->count());
        $this->assertSame($incidentId, $message->fresh()->incident_id);
        $this->assertSame(1, IncidentIncomingEmailLink::query()->where('incident_id', $incidentId)->count());
    }

    public function test_concurrent_emails_for_same_order_share_one_active_service_case(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $order = $this->seedCustomerOrder('concurrent@example.com');

        $first = $this->ingestEmail(
            fromEmail: 'concurrent@example.com',
            subject: 'First message',
            rfcMessageId: '<first-concurrent@radium.test>',
            providerMessageId: 'prov-concurrent-1',
            threadId: 'thread-concurrent-1',
        );
        $second = $this->ingestEmail(
            fromEmail: 'concurrent@example.com',
            subject: 'Second message',
            rfcMessageId: '<second-concurrent@radium.test>',
            providerMessageId: 'prov-concurrent-2',
            threadId: 'thread-concurrent-2',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $first?->status);
        $this->assertSame(IncomingEmailMessageStatus::Linked, $second?->status);
        $this->assertNotNull($first?->incident_id);
        $this->assertSame($first->incident_id, $second?->incident_id);
        $this->assertSame(1, Incident::query()->where('order_id', $order->id)->whereIn(
            'status',
            IncidentStatus::operationallyActive(),
        )->count());
        $this->assertSame(2, IncidentIncomingEmailLink::query()->where('incident_id', $first->incident_id)->count());
    }

    public function test_existing_active_sc_link_path_unchanged_with_flag_on(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        [$order, $incident] = $this->seedCustomerWithOpenIncident('linked@example.com');
        $before = Incident::query()->count();

        $message = $this->ingestEmail(
            fromEmail: 'linked@example.com',
            subject: 'Follow up on open case',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertSame($incident->id, $message?->incident_id);
        $this->assertSame($order->id, $message?->order_id);
        $this->assertSame($before, Incident::query()->count());
        $this->assertSame(1, IncidentIncomingEmailLink::query()->where('incident_id', $incident->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_flag_on_still_parks_internal_operational_as_historical(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $order = $this->seedCustomerOrder('accounts@vendor-partner.com');
        $before = Incident::query()->count();

        $message = $this->ingestEmail(
            fromEmail: 'accounts@vendor-partner.com',
            subject: 'Purchase order PO #4451 from supplier',
            preview: 'Please find the vendor invoice attached.',
        );

        $this->assertSame(IncomingEmailMessageStatus::HistoricalCustomer, $message?->status);
        $this->assertNull($message?->incident_id);
        $this->assertSame(IncomingEmailClassification::VendorAction, $message?->classification);
        $this->assertSame($before, Incident::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.historical_customer',
            'auditable_id' => $message->id,
        ]);
    }

    public function test_flag_on_auto_creates_inquiry_for_unknown_customer(): void
    {
        config(['inbound_email.auto_create_service_case' => true]);

        $beforeOrders = Order::query()->count();
        $beforeIncidents = Incident::query()->count();

        $message = $this->ingestEmail(
            fromEmail: 'brand.new@example.com',
            subject: 'Hello from a new sender',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message?->status);
        $this->assertNotNull($message?->incident_id);
        $this->assertSame($beforeOrders + 1, Order::query()->count());
        $this->assertSame($beforeIncidents + 1, Incident::query()->count());

        $incident = Incident::query()->findOrFail($message->incident_id);
        $this->assertTrue(Order::isInquiryOrderId($incident->order->order_id));
        $this->assertSame(IncidentSource::Email, $incident->source);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $incident->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'incoming_email.needs_review',
            'auditable_id' => $message->id,
        ]);
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

    /**
     * @return array{0: Order, 1: Incident}
     */
    private function seedCustomerWithOpenIncident(string $email): array
    {
        $order = $this->seedCustomerOrder($email);
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-BRANCH-B-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Existing open incident.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $creator->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return [$order, $incident];
    }

    private function seedCustomerOrder(string $email): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-BRANCH-B-'.uniqid(),
            'serial_number' => 'SN-BRANCH-B-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Email Customer',
            'customer_phone' => '9876501234',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
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
