<?php

namespace Tests\Feature\Dashboard;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\Remark;
use App\Models\User;
use App\Services\BusinessHoldService;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Dashboard\TeamActivitySalesLeadBacklogCleanupService;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamActivitySalesLeadBacklogCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_selects_team_activity_pending_sales_lead_email_backlog(): void
    {
        $assignee = $this->createAssignee();
        $target = $this->createBacklogIncident($assignee, 'INQ-BACKLOG-001');

        app(DashboardSnapshotStore::class)->forget();

        $candidates = app(TeamActivitySalesLeadBacklogCleanupService::class)->candidates($assignee);

        $this->assertCount(1, $candidates);
        $this->assertSame($target->id, $candidates->first()->id);
        $this->assertNull(app(TeamActivitySalesLeadBacklogCleanupService::class)->skipReason($target->fresh()));
    }

    public function test_excludes_general_cashfree_case_from_candidates(): void
    {
        $assignee = $this->createAssignee();
        $this->createBacklogIncident($assignee, 'INQ-BACKLOG-002');
        $this->createAssignedIncident(
            assignee: $assignee,
            orderId: 'RD-GENERAL-001',
            category: 'General',
            source: IncidentSource::Cashfree,
            origin: AssignmentOrigin::Sales,
        );

        app(DashboardSnapshotStore::class)->forget();

        $candidates = app(TeamActivitySalesLeadBacklogCleanupService::class)->candidates($assignee);

        $this->assertCount(1, $candidates);
        $this->assertSame('Sales Lead', $candidates->first()->category);
        $this->assertSame(IncidentSource::Email, $candidates->first()->source);
    }

    public function test_excludes_non_email_sales_lead_cases(): void
    {
        $assignee = $this->createAssignee();
        $this->createBacklogIncident($assignee, 'INQ-BACKLOG-003');
        $this->createAssignedIncident(
            assignee: $assignee,
            orderId: 'INQ-CALL-001',
            category: 'Sales Lead',
            source: IncidentSource::Call,
            origin: AssignmentOrigin::Sales,
        );

        app(DashboardSnapshotStore::class)->forget();

        $this->assertCount(1, app(TeamActivitySalesLeadBacklogCleanupService::class)->candidates($assignee));
    }

    public function test_excludes_non_sales_origin_cases(): void
    {
        $assignee = $this->createAssignee();
        $this->createBacklogIncident($assignee, 'INQ-BACKLOG-004');
        $this->createAssignedIncident(
            assignee: $assignee,
            orderId: 'INQ-MANUAL-001',
            category: 'Sales Lead',
            source: IncidentSource::Email,
            origin: AssignmentOrigin::Manual,
        );

        app(DashboardSnapshotStore::class)->forget();

        $this->assertCount(1, app(TeamActivitySalesLeadBacklogCleanupService::class)->candidates($assignee));
    }

    public function test_excludes_completed_queue_case_with_transaction_id(): void
    {
        $assignee = $this->createAssignee();
        $this->createBacklogIncident($assignee, 'INQ-BACKLOG-005');
        $this->createAssignedIncident(
            assignee: $assignee,
            orderId: 'INQ-COMPLETE-001',
            category: 'Sales Lead',
            source: IncidentSource::Email,
            origin: AssignmentOrigin::Sales,
            transactionId: 'txn-complete-001',
        );

        app(DashboardSnapshotStore::class)->forget();

        $this->assertCount(1, app(TeamActivitySalesLeadBacklogCleanupService::class)->candidates($assignee));
    }

    public function test_skips_sales_lead_with_active_refund_hold(): void
    {
        $assignee = $this->createAssignee();
        $ops = User::factory()->create(['is_active' => true]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $held = $this->createBacklogIncident($assignee, 'INQ-BACKLOG-006');

        $refund = RefundRequest::query()->create([
            'incident_id' => $held->id,
            'order_id' => $held->order_id,
            'status' => RefundStatus::Pending,
            'reference_no' => 'REF-TEST-001',
            'amount' => 100,
            'reason' => 'Test refund hold',
            'requested_by' => $ops->id,
        ]);

        app(BusinessHoldService::class)->activateRefundHold($held->fresh(), $refund, $ops);

        app(DashboardSnapshotStore::class)->forget();

        $service = app(TeamActivitySalesLeadBacklogCleanupService::class);

        $this->assertSame('active business hold', $service->skipReason($held->fresh()));

        $summary = $service->cleanup($assignee, dryRun: true);

        $this->assertSame(1, $summary->candidatesFound);
        $this->assertSame(0, $summary->wouldClose);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(['active business hold' => 1], $summary->skipReasons);
    }

    public function test_dry_run_does_not_close_cases(): void
    {
        $assignee = $this->createAssignee();
        $incident = $this->createBacklogIncident($assignee, 'INQ-BACKLOG-007');

        app(DashboardSnapshotStore::class)->forget();

        $summary = app(TeamActivitySalesLeadBacklogCleanupService::class)->cleanup($assignee, dryRun: true);

        $this->assertSame(1, $summary->candidatesFound);
        $this->assertSame(1, $summary->wouldClose);
        $this->assertSame(0, $summary->casesClosed);
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);
        $this->assertDatabaseMissing('remarks', [
            'remarkable_id' => $incident->id,
            'body' => TeamActivitySalesLeadBacklogCleanupService::REMARK,
        ]);
    }

    public function test_execute_closes_case_with_remark_audit_and_preserves_order(): void
    {
        $assignee = $this->createAssignee();
        $incident = $this->createBacklogIncident($assignee, 'INQ-BACKLOG-008');
        $order = $incident->order;
        $originalOrder = $order->only([
            'order_id',
            'transaction_id',
            'serial_number',
            'status',
        ]);

        app(DashboardSnapshotStore::class)->forget();

        $summary = app(TeamActivitySalesLeadBacklogCleanupService::class)->cleanup($assignee, dryRun: false);

        $this->assertSame(1, $summary->casesClosed);
        $this->assertSame(0, $summary->skipped);
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame($assignee->id, $incident->fresh()->assigned_to_user_id);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => TeamActivitySalesLeadBacklogCleanupService::REMARK,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => TeamActivitySalesLeadBacklogCleanupService::EVENT_ARCHIVED,
        ]);

        $this->assertSame($originalOrder, $order->fresh()->only([
            'order_id',
            'transaction_id',
            'serial_number',
            'status',
        ]));
    }

    public function test_command_refuses_to_run_without_dry_run_or_execute(): void
    {
        $assignee = $this->createAssignee();

        $this->artisan('team-activity:cleanup-sales-lead-backlog', [
            '--user' => $assignee->id,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Refusing to run without --dry-run');
    }

    public function test_command_requires_user_option(): void
    {
        $this->artisan('team-activity:cleanup-sales-lead-backlog', [
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('--user option is required');
    }

    private function createAssignee(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user->fresh();
    }

    private function createBacklogIncident(User $assignee, string $orderId): Incident
    {
        return $this->createAssignedIncident(
            assignee: $assignee,
            orderId: $orderId,
            category: 'Sales Lead',
            source: IncidentSource::Email,
            origin: AssignmentOrigin::Sales,
        );
    }

    private function createAssignedIncident(
        User $assignee,
        string $orderId,
        string $category,
        IncidentSource $source,
        AssignmentOrigin $origin,
        ?string $transactionId = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'customer_name' => 'Cleanup Test Customer',
            'serial_number' => null,
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'transaction_id' => $transactionId,
            'status' => 'active',
            'created_by' => $assignee->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => $category,
            'source' => $source,
            'title' => 'Cleanup candidate test case',
            'description' => 'Cleanup candidate test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $assignee->id,
            'updated_by' => $assignee->id,
            'assigned_to_user_id' => $assignee->id,
            'assignment_origin' => $origin,
        ]);
    }
}
