<?php

namespace Tests\Feature\IncomingEmail;

use App\Data\IncomingEmail\NormalizedInboundEmail;
use App\Enums\IncomingEmailClassification;
use App\Enums\IncomingEmailMessageStatus;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseCloseNotificationPreference;
use App\Enums\ServiceCaseCloseReasonForClosing;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use App\Services\IncomingEmail\IncomingEmailIngestService;
use App\Services\IncomingEmail\IncomingEmailProcessorService;
use App\Services\IncomingEmail\IncomingEmailSalesAssignmentService;
use App\Services\Operations\PresenceEngineService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEmailSalesLeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $salesAgentA;

    private User $salesAgentB;

    private User $salesAdmin;

    private User $dayAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.smart_routing_enabled' => false,
            'inbound_email.auto_create_service_case' => true,
            'inbound_email.mailboxes' => [
                'support@radiumbox.com' => 'support',
                'sales@radiumbox.com' => 'sales',
            ],
            'inbound_email.routing.sales.subject_keywords' => ['buy device', 'pricing'],
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

        $this->salesAgentA = $this->createAdminUser('sales-a@test.com', 'Sales A');
        $this->salesAgentB = $this->createAdminUser('sales-b@test.com', 'Sales B');
        $this->salesAdmin = $this->createAdminUser('sales-admin@test.com', 'Sales Admin');
        $this->dayAdmin = $this->createAdminUser('day-admin@test.com', 'Day Admin');

        app(SettingService::class)->setMany([
            'assignment.timezone' => config('app.timezone'),
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $this->dayAdmin->id,
            'assignment.night_shift_admin_user_id' => (string) $this->dayAdmin->id,
            'assignment.sales_lead_handler_user_id' => (string) $this->salesAdmin->id,
            // Intentionally unset — Sales Lead must not depend on Communication Intake.
            'assignment.communication_intake_primary_user_id' => '0',
            'assignment.communication_intake_fallback_user_id' => '0',
            'assignment.inbound_email_sales_round_robin_user_ids' => implode(',', [
                $this->salesAgentA->id,
                $this->salesAgentB->id,
            ]),
            'assignment.inbound_email_sales_round_robin_last_user_id' => '0',
        ]);
    }

    public function test_unknown_customer_sales_lead_uses_sales_round_robin_when_smart_routing_disabled(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => false,
            'inbound_email.auto_create_service_case' => true,
        ]);

        $message = $this->ingestAndProcess(
            fromEmail: 'lead@example.com',
            subject: 'Need a device quote',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertSame(IncomingEmailClassification::PossibleSalesLead, $message->classification);
        $this->assertSame('Sales Lead', $message->incident?->category);
        $this->assertSame($this->salesAgentA->id, $message->incident?->assigned_to_user_id);

        $this->assertAssignedAudit([
            'assignment_strategy' => IncomingEmailSalesAssignmentService::STRATEGY_SALES_QUEUE_ROUND_ROBIN,
            'fallback_used' => false,
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_RR,
            'reason' => 'sales_round_robin',
        ], $message->incident_id);
    }

    public function test_unknown_customer_sales_lead_uses_sales_round_robin_when_smart_routing_enabled(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => true,
            'inbound_email.auto_create_service_case' => false,
        ]);

        $message = $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'lead-smart@example.com',
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

        $this->assertAssignedAudit([
            'assignment_strategy' => IncomingEmailSalesAssignmentService::STRATEGY_SALES_QUEUE_ROUND_ROBIN,
            'fallback_used' => false,
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_RR,
        ], $message->incident_id);
    }

    public function test_no_sales_agent_falls_back_to_sales_admin(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => false,
            'inbound_email.auto_create_service_case' => true,
        ]);

        app(SettingService::class)->setMany([
            'assignment.inbound_email_sales_round_robin_user_ids' => '',
        ]);

        $message = $this->ingestAndProcess(
            fromEmail: 'no-pool@example.com',
            subject: 'Buy device enquiry',
        );

        $this->assertNotNull($message->incident?->assigned_to_user_id);
        $this->assertSame($this->salesAdmin->id, $message->incident?->assigned_to_user_id);

        $this->assertAssignedAudit([
            'assignment_strategy' => IncomingEmailSalesAssignmentService::STRATEGY_SALES_QUEUE_ROUND_ROBIN,
            'fallback_used' => true,
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_FALLBACK,
            'reason' => 'sales_rr_unavailable',
            'override_reason' => 'sales_fallback',
        ], $message->incident_id);
    }

    public function test_inactive_sales_pool_falls_back_to_sales_admin(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => true,
            'inbound_email.auto_create_service_case' => false,
        ]);

        $this->salesAgentA->update(['is_active' => false]);
        $this->salesAgentB->update(['is_active' => false]);

        $message = $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'inactive-pool@example.com',
            subject: 'Pricing question',
        );

        $this->assertSame($this->salesAdmin->id, $message->incident?->assigned_to_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'incoming_email.routed',
            'new_values->assignment_source' => 'sales_fallback',
        ]);

        $this->assertAssignedAudit([
            'fallback_used' => true,
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_FALLBACK,
            'override_reason' => 'sales_fallback',
        ], $message->incident_id);
    }

    public function test_smart_routing_enabled_allows_ira_memory_override(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => true,
            'inbound_email.auto_create_service_case' => false,
        ]);

        $iraOwner = $this->createAdminUser('ira-owner@test.com', 'IRA Owner');

        $message = $this->ingestAndProcess(
            mailbox: 'sales@radiumbox.com',
            fromEmail: 'ira-lead@example.com',
            subject: 'Buy device again',
            beforeProcess: function (IncomingEmailMessage $msg) use ($iraOwner): void {
                $msg->update([
                    'learning_owner_user_id' => $iraOwner->id,
                    'suggested_assignee_user_id' => $iraOwner->id,
                ]);
            },
        );

        $this->assertSame($iraOwner->id, $message->incident?->assigned_to_user_id);
        $this->assertNotSame($this->salesAgentA->id, $message->incident?->assigned_to_user_id);

        $this->assertAssignedAudit([
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_IRA_MEMORY,
            'fallback_used' => false,
            'reason' => 'ira_memory_override',
            'assignment_strategy' => IncomingEmailSalesAssignmentService::STRATEGY_SALES_QUEUE_ROUND_ROBIN,
        ], $message->incident_id);
    }

    public function test_smart_routing_disabled_ignores_ira_memory_and_uses_sales_rr(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => false,
            'inbound_email.auto_create_service_case' => true,
        ]);

        $iraOwner = $this->createAdminUser('ira-ignored@test.com', 'IRA Ignored');

        $message = $this->ingestAndProcess(
            fromEmail: 'ira-ignored@example.com',
            subject: 'Need pricing',
            beforeProcess: function (IncomingEmailMessage $msg) use ($iraOwner): void {
                $msg->update([
                    'learning_owner_user_id' => $iraOwner->id,
                    'suggested_assignee_user_id' => $iraOwner->id,
                ]);
            },
        );

        $this->assertSame($this->salesAgentA->id, $message->incident?->assigned_to_user_id);
        $this->assertNotSame($iraOwner->id, $message->incident?->assigned_to_user_id);

        $this->assertAssignedAudit([
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_RR,
            'fallback_used' => false,
        ], $message->incident_id);
    }

    public function test_existing_customer_new_case_still_gets_an_owner(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => true,
            'inbound_email.auto_create_service_case' => false,
        ]);

        $supportAgent = $this->createEligibleAgent('support-existing@test.com');
        $order = $this->seedCustomerOrder('existing@example.com');

        // Non-reopenable closed case → historical_customer → ExistingCustomerNewCase → Support RR.
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $closed = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-EXIST-'.uniqid(),
            'category' => 'Service',
            'source' => IncidentSource::Email,
            'title' => 'Prior closed',
            'description' => 'Prior',
            'status' => IncidentStatus::Closed,
            'high_priority' => false,
            'assigned_to_user_id' => null,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        ServiceCaseCloseOutcome::query()->create([
            'incident_id' => $closed->id,
            'reason_for_closing' => ServiceCaseCloseReasonForClosing::CustomerCancelled,
            'notification_preference' => ServiceCaseCloseNotificationPreference::No,
            'closing_summary' => 'Customer cancelled.',
            'closed_by' => $creator->id,
            'closed_at' => now(),
            'metadata' => [],
        ]);

        $message = $this->ingestAndProcess(
            mailbox: 'support@radiumbox.com',
            fromEmail: 'existing@example.com',
            subject: 'Need help again',
        );

        $this->assertSame(IncomingEmailMessageStatus::Linked, $message->status);
        $this->assertNotNull($message->incident?->assigned_to_user_id);
        $this->assertSame($supportAgent->id, $message->incident?->assigned_to_user_id);
    }

    public function test_unknown_customer_never_creates_ownerless_sales_lead(): void
    {
        config([
            'inbound_email.smart_routing_enabled' => false,
            'inbound_email.auto_create_service_case' => true,
        ]);

        app(SettingService::class)->setMany([
            'assignment.inbound_email_sales_round_robin_user_ids' => '',
            'assignment.sales_lead_handler_user_id' => '0',
            'assignment.ready_queue_day_admin_user_id' => '0',
            'assignment.ready_queue_night_admin_user_id' => '0',
        ]);

        $message = $this->ingestAndProcess(
            fromEmail: 'terminal-fallback@example.com',
            subject: 'Sales enquiry',
        );

        // Terminal fallback: shift admin (dayAdmin), never null.
        $this->assertNotNull($message->incident?->assigned_to_user_id);
        $this->assertSame($this->dayAdmin->id, $message->incident?->assigned_to_user_id);
        $this->assertAssignedAudit([
            'fallback_used' => true,
            'decision_source' => IncomingEmailSalesAssignmentService::DECISION_SALES_FALLBACK,
            'override_reason' => 'sales_fallback',
        ], $message->incident_id);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertAssignedAudit(array $expected, ?int $incidentId): void
    {
        $this->assertNotNull($incidentId);

        $audit = AuditLog::query()
            ->where('event', 'service_case.assigned')
            ->where('auditable_type', Incident::class)
            ->where('auditable_id', $incidentId)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $audit->new_values[$key] ?? null, "Audit field [{$key}] mismatch");
        }
    }

    /**
     * @param  (callable(IncomingEmailMessage): void)|null  $beforeProcess
     */
    private function ingestAndProcess(
        string $fromEmail,
        string $subject,
        string $mailbox = 'support@radiumbox.com',
        string $providerMessageId = '',
        ?callable $beforeProcess = null,
    ): IncomingEmailMessage {
        $providerMessageId = $providerMessageId !== '' ? $providerMessageId : 'prov-'.uniqid();

        $processImmediately = $beforeProcess === null;

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
        ), processImmediately: $processImmediately);

        if ($beforeProcess !== null) {
            $beforeProcess($message->fresh());
            app(IncomingEmailProcessorService::class)->process($message->fresh());
        }

        return $message->fresh(['incident.assignee', 'incident.order']);
    }

    private function seedCustomerOrder(string $email): Order
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-SALES-'.uniqid(),
            'serial_number' => 'SN-SALES-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Existing Customer',
            'customer_phone' => '9876509999',
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
