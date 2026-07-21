<?php

namespace Tests\Feature\Assignment;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairAssignmentOriginCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_dry_run_repairs_historical_manual_assignment_without_writing(): void
    {
        [$admin, $agent, $incident] = $this->createHistoricalManualAssignmentCase();

        $this->artisan('assignment:repair-origin')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('changed: 1')
            ->expectsOutputToContain('skipped: 0')
            ->expectsOutputToContain('errors: 0')
            ->expectsOutputToContain($incident->reference_no)
            ->assertSuccessful();

        $this->assertSame(AssignmentOrigin::Auto, $incident->fresh()->assignment_origin);
        $this->assertFalse(
            app(ServiceCaseAssignmentService::class)->hasManualSupportOwnership($incident->fresh(['assignee.roles'])),
        );
        $this->assertNotNull($admin);
        $this->assertNotNull($agent);
    }

    public function test_execute_repairs_historical_manual_assignment(): void
    {
        [$admin, $agent, $incident] = $this->createHistoricalManualAssignmentCase();

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->expectsOutputToContain('Execute mode')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('changed: 1')
            ->expectsOutputToContain('skipped: 0')
            ->expectsOutputToContain('errors: 0')
            ->expectsOutputToContain($incident->reference_no)
            ->assertSuccessful();

        $fresh = $incident->fresh(['assignee.roles', 'order', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue(app(ServiceCaseAssignmentService::class)->hasManualSupportOwnership($fresh));
        $this->assertFalse($this->adminReadyQueueContains($fresh));
        $this->assertNotNull($admin);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
    }

    public function test_auto_assignment_is_unchanged(): void
    {
        $admin = $this->createAdminUser();
        $agent = $this->createAgentUser();

        $order = $this->createValidatedOrder($admin, 'RD-AUTO-ORIGIN-1');
        $incident = $this->createIncident($order, $admin, assignee: $agent);

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.assigned',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $agent->id,
                'assignment_override' => true,
                'override_reason' => 'shift_admin',
            ],
        );

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('changed: 0')
            ->expectsOutputToContain('skipped: 1')
            ->expectsOutputToContain('errors: 0')
            ->assertSuccessful();

        $this->assertSame(AssignmentOrigin::Auto, $incident->fresh()->assignment_origin);
    }

    public function test_ignores_historical_manual_assignment_to_previous_assignee(): void
    {
        $admin = $this->createAdminUser();
        $firstAgent = $this->createAgentUser('agent-a@example.com', 'Agent A');
        $secondAgent = $this->createAgentUser('agent-b@example.com', 'Agent B');

        $order = $this->createValidatedOrder($admin, 'RD-PREV-ASSIGNEE');
        $incident = $this->createIncident($order, $admin, assignee: $secondAgent);

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.reassigned',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $firstAgent->id,
                'assignment_override' => true,
                'override_reason' => 'manual_reassign',
            ],
        );

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.reassigned',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $secondAgent->id,
                'reason' => 'validation_failed_support_queue',
            ],
        );

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('changed: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $this->assertSame(AssignmentOrigin::Auto, $incident->fresh()->assignment_origin);
    }

    public function test_execute_is_idempotent_on_second_run(): void
    {
        [, , $incident] = $this->createHistoricalManualAssignmentCase();

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->assertSuccessful();

        $this->assertSame(AssignmentOrigin::Manual, $incident->fresh()->assignment_origin);

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->expectsOutputToContain('scanned: 0')
            ->expectsOutputToContain('changed: 0')
            ->expectsOutputToContain('skipped: 0')
            ->assertSuccessful();
    }

    public function test_escalation_establishing_event_is_repaired(): void
    {
        $admin = $this->createAdminUser();
        $specialist = User::factory()->create(['name' => 'Escalation Specialist']);
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        $order = $this->createValidatedOrder($admin, 'RD-ESCALATION-ORIGIN');
        $incident = $this->createIncident($order, $admin, assignee: $specialist);

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.escalated',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $specialist->id,
                'reason' => 'Needs specialist review',
            ],
        );

        $this->artisan('assignment:repair-origin', ['--execute' => true])
            ->expectsOutputToContain('changed: 1')
            ->expectsOutputToContain('service_case.escalated')
            ->assertSuccessful();

        $this->assertSame(AssignmentOrigin::Manual, $incident->fresh()->assignment_origin);
    }

    /**
     * @return array{0: User, 1: User, 2: Incident}
     */
    private function createHistoricalManualAssignmentCase(): array
    {
        $admin = $this->createAdminUser();
        $agent = $this->createAgentUser();

        $order = $this->createValidatedOrder($admin, 'RD3447839');
        $incident = $this->createIncident($order, $admin, assignee: $agent);

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.assigned',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $admin->id,
                'assignment_override' => true,
                'override_reason' => 'shift_admin',
            ],
        );

        app(AuditLogService::class)->log(
            userId: $admin->id,
            event: 'service_case.reassigned',
            auditable: $incident,
            newValues: [
                'assigned_to_user_id' => $agent->id,
                'assignment_override' => true,
                'override_reason' => 'manual_reassign',
            ],
        );

        return [$admin, $agent, $incident];
    }

    private function createAdminUser(string $email = 'admin@example.com', string $name = 'Admin User'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email = 'agent@example.com', string $name = 'Agent User'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function createValidatedOrder(User $creator, string $orderId): Order
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'B47206999',
            'device_model' => 'FM 220',
            'product_name' => 'FM 220',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        return $order;
    }

    private function createIncident(Order $order, User $creator, ?User $assignee = null): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $assignee?->id,
            'assignment_origin' => AssignmentOrigin::Auto,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function adminReadyQueueContains(Incident $incident): bool
    {
        app(DashboardSnapshotStore::class)->forget();

        return DashboardSnapshot::load()
            ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
            ->contains(fn (Incident $case): bool => $case->id === $incident->id);
    }
}
