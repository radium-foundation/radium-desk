<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncidentIncomingEmailLink;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Models\User;
use App\Notifications\NewEmailReceivedNotification;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncomingEmailSmartRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $supportAgentA;

    private User $supportAgentB;

    private User $salesAgentA;

    private User $salesAgentB;

    private User $refundAgent;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.smart_routing_enabled' => true,
            'inbound_email.auto_create_service_case' => false,
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
                'sales@radiumbox.com' => 'sales',
                'refund@radiumbox.com' => 'refund',
            ],
            'inbound_email.routing.sales.subject_keywords' => ['buy device', 'pricing'],
            'inbound_email.routing.refund.subject_keywords' => ['refund request'],
            'inbound_email.routing.support.subject_keywords' => ['not working'],
            'inbound_email.ignored_labels' => ['SPAM', 'TRASH', 'CATEGORY_PROMOTIONS'],
            'inbound_email.system_sender_patterns' => ['mailer-daemon@'],
            'inbound_email.system_from_names' => ['mail delivery subsystem'],
            'inbound_email.auto_responder_header_tokens' => ['auto-submitted'],
            'inbound_email.preview_max_chars' => 280,
            'inbound_email.blocked_senders' => [],
            'inbound_email.blocked_domains' => [],
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'service_case_assignment.automation_grace_period_enabled' => false,
            'service_case_assignment.round_robin_enabled' => true,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'name' => 'System',
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->supportAgentA = $this->createEligibleAgent('support-a@test.com');
        $this->supportAgentB = $this->createEligibleAgent('support-b@test.com');
        $this->salesAgentA = $this->createAdminUser('sales-a@test.com');
        $this->salesAgentB = $this->createAdminUser('sales-b@test.com');
        $this->refundAgent = $this->createAdminUser('refund-agent@test.com');

        $dayAdmin = $this->createAdminUser('day-smart@test.com');

        app(SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $dayAdmin->id,
            'assignment.inbound_email_sales_round_robin_user_ids' => implode(',', [
                $this->salesAgentA->id,
                $this->salesAgentB->id,
            ]),
            'assignment.inbound_email_refund_team_user_ids' => (string) $this->refundAgent->id,
            'assignment.inbound_email_sales_round_robin_last_user_id' => '0',
            'assignment.inbound_email_refund_round_robin_last_user_id' => '0',
        ]);
    }

    public function test_support_mailbox_routes_unknown_email_to_support_round_robin(): void
    {
        Notification::fake();

        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'unknown@example.com',
            subject: 'Help please',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertNotNull($message->incident_id);
        $this->assertSame('Service', $message->incident?->category);
        $this->assertSame($this->supportAgentA->id, $message->incident?->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->route' => 'support_enquiry',
            'new_values->assignment_source' => 'support_round_robin',
        ]);

        Notification::assertSentTo($this->supportAgentA, NewEmailReceivedNotification::class);
    }

    public function test_sales_mailbox_routes_to_sales_round_robin(): void
    {
        $message = $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'lead@example.com',
            subject: 'Interested in pricing',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame('Sales Lead', $message->incident?->category);
        $this->assertSame($this->salesAgentA->id, $message->incident?->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->route' => 'sales_enquiry',
            'new_values->assignment_source' => 'sales_round_robin',
        ]);
    }

    public function test_refund_keyword_routes_to_refund_team(): void
    {
        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'buyer@example.com',
            subject: 'Refund request for order 123',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame('Refund', $message->incident?->category);
        $this->assertSame($this->refundAgent->id, $message->incident?->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->route' => 'refund_enquiry',
            'new_values->assignment_source' => 'refund_team_round_robin',
        ]);
    }

    public function test_unclassified_unknown_email_becomes_needs_human(): void
    {
        $message = $this->ingestAndProcess(
            mailbox: 'other@radiumbox.com',
            fromEmail: 'mystery@example.com',
            subject: 'Hello there',
        );

        $this->assertSame(IncomingEmailMessageStatus::NeedsReview, $message->status);
        $this->assertNull($message->incident_id);
        $this->assertSame(IncomingEmailClassification::UnknownCustomer, $message->classification);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->route' => 'needs_human',
            'new_values->assignment_source' => 'none',
        ]);
    }

    public function test_existing_customer_without_sc_assigns_previous_owner(): void
    {
        $order = $this->seedCustomerOrder('returning@example.com');
        $previousOwner = $this->createEligibleAgent('previous-owner@test.com');

        $this->createClosedIncident($order, $previousOwner, reopenable: false);

        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'returning@example.com',
            subject: 'Follow up issue',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame($previousOwner->id, $message->incident?->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->route' => 'existing_customer_new_case',
            'new_values->assignment_source' => 'previous_account_owner',
        ]);
    }

    public function test_existing_customer_without_sc_falls_back_to_support_round_robin(): void
    {
        $order = $this->seedCustomerOrder('no-owner@example.com');

        $this->createClosedIncident($order, assignee: null, reopenable: false);

        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'no-owner@example.com',
            subject: 'Need help again',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame($this->supportAgentA->id, $message->incident?->assigned_to_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->assignment_source' => 'support_round_robin',
        ]);
    }

    public function test_duplicate_prevention_reuses_active_service_case(): void
    {
        $order = $this->seedCustomerOrder('dup@example.com');

        $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'dup@example.com',
            subject: 'First email',
        );

        $before = Incident::query()->count();

        $second = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'dup@example.com',
            subject: 'Second email',
            providerMessageId: 'dup-email-2',
        );

        $this->assertSame($before, Incident::query()->count());
        $this->assertSame(2, IncidentIncomingEmailLink::query()->count());
        $this->assertSame(IncomingEmailMessageStatus::Linked, $second->status);
    }

    public function test_sales_round_robin_advances_cursor(): void
    {
        $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'lead1@example.com',
            subject: 'Buy device enquiry',
            providerMessageId: 'sales-1',
        );

        $second = $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'lead2@example.com',
            subject: 'Buy device pricing',
            providerMessageId: 'sales-2',
        );

        $this->assertSame($this->salesAgentB->id, $second->incident?->assigned_to_user_id);
    }

    public function test_timeline_includes_routing_context_for_support_route(): void
    {
        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'timeline-unknown@example.com',
            subject: 'Device not working',
        );

        $routingAudit = AuditLog::query()
            ->where('event', 'incoming_email.routed')
            ->where('new_values->incoming_email_message_id', $message->id)
            ->first();

        $this->assertNotNull($routingAudit);
        $this->assertSame('support_enquiry', $routingAudit->new_values['route'] ?? null);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.linked',
            'auditable_id' => $message->incident_id,
        ]);
    }

    private function createClosedIncident(Order $order, ?User $assignee, bool $reopenable = true): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-SMART-'.uniqid(),
            'category' => 'Service',
            'source' => IncidentSource::Email,
            'title' => 'Closed case',
            'description' => 'Previously closed.',
            'status' => IncidentStatus::Closed,
            'high_priority' => false,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        if (! $reopenable) {
            ServiceCaseCloseOutcome::query()->create([
                'incident_id' => $incident->id,
                'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerCancelled,
                'notification_preference' => ServiceCaseCloseNotificationPreference::No,
                'closing_summary' => 'Customer cancelled.',
                'closed_by' => $creator->id,
                'closed_at' => now(),
                'metadata' => [],
            ]);
        }

        return $incident;
    }

    private function ingestAndProcess(
        string $mailbox,
        string $fromEmail,
        string $subject,
        string $providerMessageId = 'fixture-msg-1',
    ): IncomingEmailMessage {
        $message = app(IncomingEmailIngestService::class)->ingest(new NormalizedInboundEmail(
            mailbox: $mailbox,
            provider: 'fixture',
            providerMessageId: $providerMessageId,
            rfcMessageId: '<'.$providerMessageId.'@radium.test>',
            threadId: 'thread-'.$providerMessageId,
            fromEmail: $fromEmail,
            fromName: 'Customer',
            toEmails: [$mailbox],
            subject: $subject,
            preview: 'Body preview for '.$subject,
            receivedAt: now(),
            attachmentCount: 0,
            headers: [],
            labels: [],
            rawPayload: ['fixture' => true],
        ));

        app(IncomingEmailProcessorService::class)->process($message->fresh());

        return $message->fresh(['incident.assignee', 'incident.order']);
    }

    private function seedCustomerOrder(string $email): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-SMART-'.uniqid(),
            'serial_number' => 'SN-SMART-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Email Customer',
            'customer_phone' => '9876501234',
            'customer_email' => $email,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function createEligibleAgent(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        app(PresenceEngineService::class)->startSession($user);

        return $user;
    }

    private function createAdminUser(string $email): User
    {
        $user = User::factory()->create([
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
