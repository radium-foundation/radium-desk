<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\RemarkOrigin;
use App\Enums\TeamAvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Remark;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\AdminActivationMetricsService;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\RoleAwareKpiMetricsService;
use App\Services\Operations\SupportActivityMetricsService;
use App\Services\Operations\Workforce360Service;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RoleAwareKpiMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['dashboard-team-activity.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-07-06 11:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_support_agent_outcome_is_distinct_cases_and_effort_is_customer_touches(): void
    {
        $agent = $this->createSupportAgent();
        [$incident] = $this->createIncident($agent);

        foreach (range(1, 5) as $index) {
            AuditLog::query()->create([
                'user_id' => $agent->id,
                'event' => 'service_case.status_changed',
                'auditable_type' => $incident->getMorphClass(),
                'auditable_id' => $incident->id,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'notification.dispatched',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now()->subMinute(),
        ]);

        $metrics = app(SupportActivityMetricsService::class)->metricsFor($agent);

        $this->assertSame(1, $metrics->outcome);
        $this->assertSame(6, $metrics->effort);
        $this->assertSame(5, $metrics->breakdown['status_updates']);
        $this->assertSame(1, $metrics->breakdown['emails']);
    }

    public function test_admin_batch_activation_counts_one_session_and_many_orders(): void
    {
        $admin = $this->createActivationAdmin();
        $base = now();

        foreach (range(1, 35) as $index) {
            $order = $this->createOrder($admin);

            AuditLog::query()->create([
                'user_id' => $admin->id,
                'event' => 'service_reference.assigned',
                'auditable_type' => $order->getMorphClass(),
                'auditable_id' => $order->id,
                'new_values' => ['transaction_id' => 'SRV-BATCH-001'],
                'created_at' => $base->copy()->addMilliseconds($index * 50),
            ]);
        }

        $metrics = app(AdminActivationMetricsService::class)->metricsFor($admin);

        $this->assertSame(35, $metrics->outcome);
        $this->assertSame(1, $metrics->effort);
        $this->assertSame(35.0, $metrics->breakdown['average_orders_per_session']);
    }

    public function test_role_aware_service_never_mixes_support_and_activation_profiles(): void
    {
        $agent = $this->createSupportAgent();
        $admin = $this->createActivationAdmin();
        [$incident] = $this->createIncident($agent);
        $order = $this->createOrder($admin);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_reference.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => ['transaction_id' => 'SRV-ADMIN-001'],
            'created_at' => now(),
        ]);

        $metrics = app(RoleAwareKpiMetricsService::class)->metricsForUsers([$agent->id, $admin->id]);

        $this->assertSame('support', $metrics[$agent->id]->profile->value);
        $this->assertSame('Cases Worked', $metrics[$agent->id]->outcomeLabel());
        $this->assertSame('Customer Touches', $metrics[$agent->id]->effortLabel());
        $this->assertSame(1, $metrics[$agent->id]->outcome);

        $this->assertSame('activation', $metrics[$admin->id]->profile->value);
        $this->assertSame('Orders Activated', $metrics[$admin->id]->outcomeLabel());
        $this->assertSame('Activation Sessions', $metrics[$admin->id]->effortLabel());
        $this->assertSame(1, $metrics[$admin->id]->outcome);
        $this->assertSame(1, $metrics[$admin->id]->effort);
    }

    public function test_team_activity_panel_exposes_outcome_and_effort_columns(): void
    {
        $agent = $this->createSupportAgent();
        [$incident] = $this->createIncident($agent);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        $remark = Remark::query()->create([
            'user_id' => $agent->id,
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Manual note',
        ]);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'created',
            'auditable_type' => $remark->getMorphClass(),
            'auditable_id' => $remark->id,
            'new_values' => ['origin' => RemarkOrigin::Manual->value],
            'created_at' => now(),
        ]);

        $row = collect(app(TeamActivityPanelService::class)->build()->agents)
            ->firstWhere('id', $agent->id);

        $this->assertNotNull($row);
        $this->assertSame('Cases Worked', $row->outcomeLabel);
        $this->assertSame(1, $row->outcomeCount);
        $this->assertSame('Customer Touches', $row->effortLabel);
        $this->assertSame(2, $row->effortCount);
        $this->assertSame(1, $row->todayCount);
    }

    public function test_ira_snapshot_uses_role_aware_performance_totals(): void
    {
        $agent = $this->createSupportAgent();
        $admin = $this->createActivationAdmin();
        [$incident] = $this->createIncident($agent);
        $order = $this->createOrder($admin);

        AuditLog::query()->create([
            'user_id' => $agent->id,
            'event' => 'service_case.status_changed',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_reference.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => ['transaction_id' => 'SRV-ADMIN-002'],
            'created_at' => now(),
        ]);

        $performance = app(IraMemoryService::class)->collectSnapshotData()->performance;

        $this->assertSame(1, $performance['support_cases_worked']);
        $this->assertSame(1, $performance['support_customer_touches']);
        $this->assertSame(1, $performance['activation_orders_activated']);
        $this->assertSame(1, $performance['activation_sessions']);
        $this->assertSame(1, $performance['completed_cases']);
        $this->assertSame(1, $performance['customer_communications']);
    }

    public function test_workforce_member_snapshot_includes_role_aware_outcome_and_effort(): void
    {
        $viewer = $this->createActivationAdmin('Ops Viewer');
        $order = $this->createOrder($viewer);

        AuditLog::query()->create([
            'user_id' => $viewer->id,
            'event' => 'service_reference.assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'new_values' => ['transaction_id' => 'SRV-ADMIN-003'],
            'created_at' => now(),
        ]);

        $member = app(Workforce360Service::class)->member($viewer, $viewer);
        $performance = $member->overview['performance'];

        $this->assertSame('activation', $performance['kpi_profile']);
        $this->assertSame('Orders Activated', $performance['outcome_label']);
        $this->assertSame(1, $performance['outcome_count']);
        $this->assertSame('Activation Sessions', $performance['effort_label']);
        $this->assertSame(1, $performance['effort_count']);
    }

    public function test_failed_activations_and_driver_guides_are_tracked_for_admins(): void
    {
        $admin = $this->createActivationAdmin();
        $order = $this->createOrder($admin);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'transaction.assignment_blocked',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'event' => 'service_reference.driver_guide_sent',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'created_at' => now(),
        ]);

        $metrics = app(AdminActivationMetricsService::class)->metricsFor($admin);

        $this->assertSame(1, $metrics->breakdown['failed_activations']);
        $this->assertSame(1, $metrics->breakdown['driver_guides_sent']);
    }

    private function createSupportAgent(string $name = 'Support Agent'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_duration_minutes' => 15,
        ]);

        return $user;
    }

    private function createActivationAdmin(string $name = 'Activation Admin'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_duration_minutes' => 15,
        ]);

        return $user;
    }

    /**
     * @return array{0: Incident}
     */
    private function createIncident(User $agent): array
    {
        $order = $this->createOrder($agent);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'assigned_to_user_id' => $agent->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'KPI test case',
            'description' => 'KPI test case.',
            'status' => 'open',
            'created_by' => $agent->id,
        ]);

        return [$incident];
    }

    private function createOrder(User $user): Order
    {
        return Order::query()->create([
            'order_id' => 'RD'.random_int(1000000, 9999999),
            'serial_number' => 'SN-KPI',
            'customer_name' => 'KPI Test Customer',
            'product_name' => 'RBX 110',
            'device_model' => 'RBX 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }
}
