<?php

namespace Tests\Feature;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\AuditLog;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderDeviceModelService;
use App\Services\OrderSerialService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RemarkService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\ServiceCaseStatusService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ManualAgentOwnershipWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'service_case_assignment.automation_grace_period_enabled' => true,
            'service_case_assignment.round_robin_enabled' => true,
        ]);
    }

    public function test_admin_manual_assign_to_agent_removes_case_from_admin_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $order = $this->createValidatedOrder($admin, 'RD-MANUAL-ASSIGN-1');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(DashboardSnapshotStore::class)->forget();

        $this->assertTrue($this->adminReadyQueueContains($incident));

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);

        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertFalse($this->adminReadyQueueContains($fresh));
        $this->assertTrue(
            app(OperationsQueueClassifier::class)->matchesMyWork($fresh, $agent),
        );

        Carbon::setTestNow();
    }

    public function test_manual_assign_to_same_assignee_persists_manual_origin(): void
    {
        $admin = $this->createAdminUser('shipra@example.com', 'Shipra Kumari');
        $gaurav = $this->createAgentUser('gaurav@example.com', 'Gaurav');

        $order = $this->createValidatedOrder($admin, 'RD-SAME-ASSIGNEE-1');
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $gaurav->id,
            'assignment_origin' => AssignmentOrigin::Auto,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(ServiceCaseAssignmentService::class)->reassign($incident, $gaurav, $admin);

        $fresh = $this->freshIncident($incident);

        $this->assertSame($gaurav->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);

        $auditLog = AuditLog::query()
            ->where('event', 'service_case.reassigned')
            ->where('auditable_id', $incident->id)
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertSame('manual', $auditLog->new_values['assignment_origin'] ?? null);
        $this->assertSame('manual_reassign', $auditLog->new_values['override_reason'] ?? null);
    }

    public function test_manually_assigned_ready_case_appears_in_agent_my_work(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');

        $order = $this->createValidatedOrder($admin, 'RD-MANUAL-MYWORK-1');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);
        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $agent);

        $this->assertTrue($myWork->contains(fn (Incident $case): bool => $case->id === $fresh->id));
    }

    public function test_manual_agent_serial_correction_keeps_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-MANUAL-SERIAL',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'cashfree_payment_id' => 'cf_pay_serial',
        ]);
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        app(OrderSerialService::class)->assignSerialNumber($order, '7881953', $agent);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
        $this->assertFalse($this->adminReadyQueueContains($fresh));

        Carbon::setTestNow();
    }

    public function test_manual_agent_device_model_correction_keeps_ownership(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $agent->givePermissionTo(RolePermissionSeeder::PERMISSION_CORRECT_ORDER_IDENTITY);
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-MANUAL-MODEL',
            'serial_number' => '7881953',
            'device_model' => null,
            'product_name' => null,
            'device_model_id' => null,
            'cashfree_payment_id' => 'cf_pay_model',
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);
        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        app(OrderDeviceModelService::class)->assignDeviceModel($order, $deviceModel, $agent);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);

        $this->assertSame($agent->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($fresh));
        $this->assertFalse($this->adminReadyQueueContains($fresh));

        Carbon::setTestNow();
    }

    public function test_manual_agent_can_close_case(): void
    {
        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');

        $order = $this->createValidatedOrder($admin, 'RD-MANUAL-CLOSE');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(ServiceCaseAssignmentService::class)->reassign($incident, $agent, $admin);

        app(RemarkService::class)->createForRemarkable(
            remarkable: $incident->fresh(),
            actor: $agent,
            body: 'Resolved during manual ownership.',
        );

        $incident->order?->update(['transaction_id' => 'TX-MANUAL-CLOSE']);

        app(ServiceCaseStatusService::class)->updateStatus(
            incident: $incident->fresh(),
            status: IncidentStatus::Closed,
            actor: $agent,
        );

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
    }

    public function test_agent_manual_return_to_admin_reappears_in_ready_queue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->configureAssignmentSettings($admin->id, $admin->id);

        $order = $this->createValidatedOrder($admin, 'RD-MANUAL-RETURN');
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $assignmentService = app(ServiceCaseAssignmentService::class);
        $assignmentService->reassign($incident, $agent, $admin);

        app(DashboardSnapshotStore::class)->forget();
        $this->assertFalse($this->adminReadyQueueContains($incident));

        $assignmentService->reassign($incident->fresh(), $admin, $agent);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);

        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Manual, $fresh->assignment_origin);
        $this->assertTrue($this->adminReadyQueueContains($fresh));

        Carbon::setTestNow();
    }

    public function test_auto_owned_agent_still_reassigns_to_admin_after_validation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 14:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdminUser('admin@example.com', 'Admin User');
        $agent = $this->createAgentUser('agent@example.com', 'Agent User');
        $this->configureAssignmentSettings($admin->id, $admin->id);
        $actor = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-AUTO-ESCALATE',
            'serial_number' => '7881953',
            'product_name' => 'Unknown',
            'device_model' => 'Unknown',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Auto ownership escalation',
            'description' => 'Auto ownership escalation.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'assignment_origin' => AssignmentOrigin::Auto,
            'created_by' => $actor->id,
        ]);

        $order->update([
            'device_model' => 'MFS 110',
            'product_name' => 'MFS 110',
        ]);

        app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility(
            $order->fresh(),
            $actor,
        );

        $fresh = $incident->fresh();

        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame(AssignmentOrigin::Auto, $fresh->assignment_origin);

        Carbon::setTestNow();
    }

    private function configureAssignmentSettings(int $dayAdminId, int $nightAdminId): void
    {
        app(SettingService::class)->setMany([
            'assignment.timezone' => 'Asia/Kolkata',
            'assignment.day_shift_start' => '09:00',
            'assignment.day_shift_end' => '18:30',
            'assignment.day_shift_admin_user_id' => (string) $dayAdminId,
            'assignment.night_shift_admin_user_id' => (string) $nightAdminId,
            'assignment.fallback_admin_1_user_id' => '',
            'assignment.fallback_admin_2_user_id' => '',
            'assignment.automation_grace_period_seconds' => '60',
        ]);
    }

    private function createAdminUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgentUser(string $email, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(User $creator, array $overrides = []): Order
    {
        return Order::query()->create([
            'order_id' => 'RD-DEFAULT',
            'status' => 'active',
            'created_by' => $creator->id,
            ...$overrides,
        ]);
    }

    private function createValidatedOrder(User $creator, string $orderId): Order
    {
        $order = $this->createOrder($creator, [
            'order_id' => $orderId,
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'cashfree_payment_id' => 'cf_pay_'.$orderId,
        ]);
        $this->markSynced($order);

        return $order;
    }

    private function createIncident(
        Order $order,
        User $creator,
        ?User $assignee = null,
    ): Incident {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function freshIncident(Incident $incident): Incident
    {
        return $incident->fresh([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'supportAppointments',
        ]);
    }

    private function markSynced(Order $order): void
    {
        $order->update(['radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);
    }

    private function adminReadyQueueContains(Incident $incident): bool
    {
        return DashboardSnapshot::load()
            ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
            ->contains(fn (Incident $case): bool => $case->id === $incident->id);
    }
}
