<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\TeamMemberActivityService;
use App\Services\RemarkService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsModelFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_legacy_roles_retain_permissions_after_new_roles_are_seeded(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue($agent->can('incidents.update'));
        $this->assertTrue($agent->can('refunds.create'));
        $this->assertFalse($agent->can('users.manage'));

        $this->assertTrue($admin->can('users.manage'));
        $this->assertTrue($admin->can('dashboard.hardware.view'));
    }

    public function test_new_operational_roles_map_to_expected_permissions(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->assignRole(RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR);

        $hardware = User::factory()->create();
        $hardware->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue($coordinator->can('incidents.update'));
        $this->assertFalse($coordinator->can('users.manage'));

        $this->assertTrue($hardware->can('dashboard.hardware.view'));
        $this->assertTrue($hardware->can('orders.update'));

        $this->assertTrue($operationsAdmin->can('users.manage'));
        $this->assertTrue($operationsAdmin->can('operations-dashboard.view'));
    }

    public function test_queue_classifier_separates_waiting_customer_from_action_required(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-1', $creator, $creator);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $actionCase = $this->createIncident('RD-ACTION-1', $creator, $creator);

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isWaitingCustomer($waitingCase->fresh(['activeWaitingState', 'order'])));
        $this->assertSame('waiting_customer', $classifier->classify($waitingCase->fresh(['activeWaitingState', 'order', 'supportAppointments']))->value);
        $this->assertSame('action_required', $classifier->classify($actionCase->fresh(['activeWaitingState', 'order', 'supportAppointments']))->value);
    }

    public function test_scheduled_cases_have_their_own_queue(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $scheduledCase = $this->createIncident('RD-SCHED-1', $creator, $creator);
        SupportAppointment::query()->create([
            'incident_id' => $scheduledCase->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => 'morning',
            'phone_number' => '9999999999',
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $scheduledCase->fresh(['supportAppointments', 'order', 'activeWaitingState']);

        $this->assertTrue($classifier->isScheduled($incident));
        $this->assertSame('scheduled', $classifier->classify($incident)->value);
    }

    public function test_hardware_rde_orders_are_separated(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $hardwareCase = $this->createIncident('RDE-10001', $creator, $creator);
        $serviceCase = $this->createIncident('RD-10001', $creator, $creator);

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertTrue($classifier->isHardware($hardwareCase->fresh(['order'])));
        $this->assertSame('hardware', $classifier->classify($hardwareCase->fresh(['order', 'supportAppointments', 'activeWaitingState']))->value);
        $this->assertFalse($classifier->isHardware($serviceCase->fresh(['order'])));
    }

    public function test_waiting_customer_cases_are_excluded_from_action_required_counts(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-COUNT', $creator, $creator);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::Photos,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $this->createIncident('RD-ACTION-COUNT', $creator, $creator);

        $counts = DashboardSnapshot::load()->queueCounts();

        $this->assertSame(1, $counts['action_required']);
        $this->assertSame(1, $counts['waiting_customer']);
    }

    public function test_admin_dashboard_shows_unified_operation_queues(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Action Required')
            ->assertSee('Pending Review')
            ->assertSee('Waiting Customer')
            ->assertSee('Scheduled')
            ->assertSee('Attention')
            ->assertSee('Hardware')
            ->assertDontSee('dashboard-module-nav', false)
            ->assertDontSee('>Team<', false);
    }

    public function test_support_dashboard_shows_my_work_queue_instead_of_module_tabs(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('My Work')
            ->assertSee('Waiting Customer')
            ->assertDontSee('Action Required')
            ->assertDontSee('dashboard-module-nav', false);
    }

    public function test_legacy_filter_urls_redirect_to_queue_model(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', ['view' => 'hardware_orders']))
            ->assertRedirect(route('dashboard', ['queue' => 'hardware']));
    }

    public function test_activity_tracking_records_case_actions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 12:00:00'));

        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $incident = $this->createIncident('RD-ACTIVITY-1', $actor, $actor);

        app(RemarkService::class)->createForRemarkable(
            $incident,
            $actor,
            'Followed up with customer.',
        );

        $actor->refresh();

        $this->assertNotNull($actor->last_case_action_at);
        $this->assertNotNull($actor->last_active_at);

        $snapshot = app(TeamMemberActivityService::class)->snapshotFor($actor);
        $this->assertNotNull($snapshot['last_case_action_at']);

        Carbon::setTestNow();
    }

    public function test_role_display_labels_use_operations_terminology(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $roles = app(OperationsRoleService::class);

        $this->assertSame('Support Specialist', $roles->displayLabel(RolePermissionSeeder::ROLE_AGENT));
        $this->assertSame('Operations Admin', $roles->displayLabel(RolePermissionSeeder::ROLE_ADMIN));
        $this->assertSame('Support Specialist', $agent->fresh('roles')->primaryRoleLabel());
    }

    public function test_personalization_exposes_admin_and_support_queue_sets(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $personalization = app(DashboardPersonalizationService::class);

        $this->assertContains('action_required', $personalization->availableQueuesFor($admin));
        $this->assertContains('pending_review', $personalization->availableQueuesFor($admin));
        $this->assertContains('hardware', $personalization->availableQueuesFor($admin));
        $this->assertSame('my_work', $personalization->defaultQueueFor($agent));
        $this->assertContains('waiting_customer', $personalization->availableQueuesFor($agent));
    }

    public function test_open_kpi_excludes_waiting_customer_completed_and_hardware_cases(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $waitingCase = $this->createIncident('RD-OPEN-WAIT', $creator, $creator);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $this->createIncident('RD-OPEN-ACTION', $creator, $creator);
        $this->createIncident('RDE-OPEN-HW', $creator, $creator);

        $closedOrder = Order::query()->create([
            'order_id' => 'RD-OPEN-CLOSED',
            'serial_number' => 'SN-CLOSED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        Incident::query()->create([
            'order_id' => $closedOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed operations case',
            'description' => 'Closed operations case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $creator->id,
        ]);

        $snapshot = DashboardSnapshot::load();
        $queueCounts = $snapshot->queueCounts();
        $operationalKpis = $snapshot->operationalKpiCounts();

        $this->assertSame(1, $operationalKpis['open_cases']);
        $this->assertSame(1, $queueCounts['waiting_customer']);
        $this->assertSame($queueCounts['waiting_customer'], $operationalKpis['waiting_cases']);
    }

    public function test_support_user_open_kpi_is_scoped_to_assigned_work(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->createIncident('RD-SCOPE-1', $admin, $agent);
        $this->createIncident('RD-SCOPE-2', $admin, $admin);

        $agentStats = app(DashboardService::class)->statsFor($agent);
        $adminStats = app(DashboardService::class)->statsFor($admin);

        $this->assertSame(1, $agentStats['my_active_work']);
        $this->assertSame(2, $adminStats['open_cases']);
    }

    public function test_operational_kpi_counts_do_not_issue_queries_after_snapshot_load(): void
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createIncident('RD-KPI-QUERY-1', $creator, $creator);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $snapshot = DashboardSnapshot::load();
        $snapshot->queueCounts();
        $queriesAfterWarmup = count(DB::getQueryLog());

        $snapshot->operationalKpiCounts();
        $snapshot->slaCounts();

        $this->assertSame($queriesAfterWarmup, count(DB::getQueryLog()));

        DB::disableQueryLog();
    }

    public function test_dashboard_kpi_stats_preserve_pending_refund_count(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        \App\Models\RefundRequest::query()->create([
            'order_id' => Order::query()->create([
                'order_id' => 'RD-REF-KPI',
                'serial_number' => 'SN-REF',
                'product_name' => 'MFS 110',
                'device_model' => 'MFS 110',
                'status' => 'active',
                'created_by' => $admin->id,
            ])->id,
            'reference_no' => 'REF-'.now()->format('Y').'-000099',
            'amount' => 100,
            'reason' => 'Test refund',
            'status' => \App\Enums\RefundStatus::Pending,
            'requested_by' => $admin->id,
        ]);

        $stats = app(DashboardService::class)->statsFor($admin);

        $this->assertSame(1, $stats['pending_refunds']);
    }

    private function createIncident(string $orderId, User $creator, ?User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Operations foundation case',
            'description' => 'Operations foundation case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
