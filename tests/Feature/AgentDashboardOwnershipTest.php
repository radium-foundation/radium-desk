<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AgentDashboardOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_assigned_attention_case_appears_in_agent_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Attention Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $attentionCase = $this->createIncident('RD-ATTENTION-1', $creator, $agent);
        $attentionCase->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        $otherAgentCase = $this->createIncident('RD-ATTENTION-2', $creator, User::factory()->create());

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $attentionCase->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('attention', $classifier->classify($incident)->value);
        $this->assertTrue($classifier->matchesMyWork($incident, $agent));

        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $agent);

        $this->assertTrue($myWork->contains(fn (Incident $case): bool => $case->id === $attentionCase->id));
        $this->assertFalse($myWork->contains(fn (Incident $case): bool => $case->id === $otherAgentCase->id));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($attentionCase->reference_no)
            ->assertDontSee($otherAgentCase->reference_no);

        Carbon::setTestNow();
    }

    public function test_rd3442035_validation_failed_case_shows_badge_and_verify_action_on_agent_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Abhinav');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD3442035',
            'serial_number' => '54SAXXC5514586',
            'device_model' => 'MFS110',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => $creator->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Serial validation failed',
            'description' => 'Production validation failure example.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);

        $this->assertSame('attention', $classifier->classify($freshIncident)->value);
        $this->assertTrue($classifier->matchesMyWork($freshIncident, $agent));

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('RD3442035')
            ->assertSee('serial-validation-indicator--fail', false)
            ->assertSee('Verify serial/device', false);

        Carbon::setTestNow();
    }

    public function test_agent_dashboard_grid_shows_people_column_with_assignee_and_creator(): void
    {
        $agent = $this->createAgent('Grid Agent');
        $creator = User::factory()->create(['name' => 'Admin Creator']);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createIncident('RD-PEOPLE-1', $creator, $agent);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-people-avatars', false)
            ->assertSee('aria-label="Assigned To: Grid Agent"', false)
            ->assertSee('aria-label="Logged by: Admin Creator"', false)
            ->assertSee('People', false);
    }

    public function test_agent_dashboard_grid_row_actions_remain_accessible(): void
    {
        $agent = $this->createAgent('Action Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createIncident('RD-ACTION-1', $creator, $agent);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-row-actions', false)
            ->assertSee('data-workspace-trigger="action"', false)
            ->assertSee('data-workspace-incident-id="'.$incident->id.'"', false);
    }

    public function test_agent_dashboard_hides_global_metrics_and_shows_personal_kpis(): void
    {
        $agent = $this->createAgent('KPI Agent');

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Assigned Cases')
            ->assertSee('Action Required')
            ->assertDontSee('>My Work<', false)
            ->assertDontSee('>Needs Attention<', false)
            ->assertDontSee('My Active Work')
            ->assertDontSee('My Scheduled Today')
            ->assertDontSee('My Waiting Follow-ups')
            ->assertDontSee('My Completed Today')
            ->assertDontSee('>Total Active Cases<', false)
            ->assertDontSee('>Customer Waiting<', false)
            ->assertDontSee('>Pending Admin<', false)
            ->assertDontSee('>Open<', false)
            ->assertDontSee('>Overdue<', false);
    }

    public function test_admin_dashboard_keeps_global_operation_metrics(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ready Queue')
            ->assertSee('Attention')
            ->assertSee('Customer Waiting')
            ->assertSee('>Open<', false)
            ->assertSee('>Overdue<', false)
            ->assertDontSee('My Active Work')
            ->assertDontSee('My Attention');
    }

    public function test_assigned_waiting_customer_is_included_in_my_work(): void
    {
        $agent = $this->createAgent('Waiting Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-NO-FU', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $waitingCase->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);

        $this->assertTrue($classifier->isWaitingCustomer($incident));
        $this->assertTrue($classifier->matchesMyWork($incident, $agent));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());
    }

    public function test_assigned_waiting_customer_with_due_follow_up_is_included_in_my_work(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 14:00:00', 'Asia/Kolkata'));

        $agent = $this->createAgent('Follow-up Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $waitingCase = $this->createIncident('RD-WAIT-FU', $creator, $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waitingCase->id,
            'waiting_reason' => WaitingReason::Photos,
            'started_at' => now()->subDay(),
            'next_action_at' => now()->subHour(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        $classifier = app(OperationsQueueClassifier::class);
        $incident = $waitingCase->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);

        $this->assertTrue($classifier->isWaitingFollowUpDue($incident));
        $this->assertTrue($classifier->matchesMyWork($incident, $agent));
        $this->assertSame(1, DashboardSnapshot::load()->incidentsForQueue('my_work', $agent)->count());

        Carbon::setTestNow();
    }

    public function test_agent_my_attention_count_is_scoped_to_assigned_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('Agent A');
        $agentB = $this->createAgent('Agent B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 2; $index++) {
            $this->createOverdueAttentionCase("RD-A-ATT-{$index}", $creator, $agentA);
        }

        for ($index = 1; $index <= 3; $index++) {
            $this->createOverdueAttentionCase("RD-B-ATT-{$index}", $creator, $agentB);
        }

        $this->createUnassignedAttentionCase('RD-UNASSIGNED-ATT', $creator);

        $stats = app(DashboardService::class)->statsFor($agentA);

        $this->assertSame(2, $stats['my_attention']);
        $this->assertSame(3, app(DashboardService::class)->statsFor($agentB)['my_attention']);

        Carbon::setTestNow();
    }

    public function test_agent_my_attention_drilldown_shows_same_assigned_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('Drilldown Agent A');
        $agentB = $this->createAgent('Drilldown Agent B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createOverdueAttentionCase('RD-A-DRILL-1', $creator, $agentA);
        $this->createOverdueAttentionCase('RD-A-DRILL-2', $creator, $agentA);
        $this->createOverdueAttentionCase('RD-B-DRILL-1', $creator, $agentB);
        $this->createUnassignedAttentionCase('RD-UNASSIGNED-DRILL', $creator);

        $expectedHref = route('dashboard', ['filter' => 'my_attention']).'#dashboard-service-cases-panel';

        $this->actingAs($agentA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($expectedHref, false);

        $this->actingAs($agentA)
            ->get(route('dashboard', ['filter' => 'my_attention']))
            ->assertOk()
            ->assertSee('Action Required')
            ->assertSee('RD-A-DRILL-1')
            ->assertSee('RD-A-DRILL-2')
            ->assertDontSee('RD-B-DRILL-1')
            ->assertDontSee('RD-UNASSIGNED-DRILL');

        $filterCounts = app(DashboardService::class)->serviceCaseFilterCounts($agentA, $agentA);

        $this->assertSame(2, $filterCounts['my_attention']);

        Carbon::setTestNow();
    }

    public function test_agent_my_waiting_follow_ups_count_is_scoped_to_assigned_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('Waiting Agent A');
        $agentB = $this->createAgent('Waiting Agent B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 2; $index++) {
            $this->createAssignedWaitingCase("RD-A-WAIT-{$index}", $creator, $agentA);
        }

        for ($index = 1; $index <= 3; $index++) {
            $this->createAssignedWaitingCase("RD-B-WAIT-{$index}", $creator, $agentB);
        }

        $this->createAssignedWaitingCase('RD-UNASSIGNED-WAIT', $creator, null);

        $this->assertSame(2, app(DashboardService::class)->statsFor($agentA)['my_waiting_follow_ups']);
        $this->assertSame(3, app(DashboardService::class)->statsFor($agentB)['my_waiting_follow_ups']);

        Carbon::setTestNow();
    }

    public function test_agent_waiting_customer_drilldown_shows_assigned_cases_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('Waiting Drilldown A');
        $agentB = $this->createAgent('Waiting Drilldown B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAssignedWaitingCase('RD-A-WAIT-DRILL-1', $creator, $agentA);
        $this->createAssignedWaitingCase('RD-A-WAIT-DRILL-2', $creator, $agentA);
        $this->createAssignedWaitingCase('RD-B-WAIT-DRILL-1', $creator, $agentB);
        $this->createAssignedWaitingCase('RD-UNASSIGNED-WAIT-DRILL', $creator, null);

        $this->actingAs($agentA)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER]))
            ->assertOk()
            ->assertSee('RD-A-WAIT-DRILL-1')
            ->assertSee('RD-A-WAIT-DRILL-2')
            ->assertDontSee('RD-B-WAIT-DRILL-1')
            ->assertDontSee('RD-UNASSIGNED-WAIT-DRILL');

        Carbon::setTestNow();
    }

    public function test_admin_waiting_customer_queue_remains_global(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $agentA = $this->createAgent('Global Waiting A');
        $agentB = $this->createAgent('Global Waiting B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAssignedWaitingCase('RD-GLOBAL-WAIT-A', $creator, $agentA);
        $this->createAssignedWaitingCase('RD-GLOBAL-WAIT-B', $creator, $agentB);
        $this->createAssignedWaitingCase('RD-GLOBAL-WAIT-UNASSIGNED', $creator, null);

        $waitingCount = DashboardSnapshot::load()->incidentsForQueue('waiting_customer')->count();

        $this->assertSame(3, $waitingCount);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_WAITING_CUSTOMER]))
            ->assertOk()
            ->assertSee('RD-GLOBAL-WAIT-A')
            ->assertSee('RD-GLOBAL-WAIT-B')
            ->assertSee('RD-GLOBAL-WAIT-UNASSIGNED');

        Carbon::setTestNow();
    }

    public function test_admin_attention_queue_remains_global(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $agentA = $this->createAgent('Global Agent A');
        $agentB = $this->createAgent('Global Agent B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createOverdueAttentionCase('RD-GLOBAL-A-1', $creator, $agentA);
        $this->createOverdueAttentionCase('RD-GLOBAL-A-2', $creator, $agentA);
        $this->createOverdueAttentionCase('RD-GLOBAL-B-1', $creator, $agentB);
        $this->createOverdueAttentionCase('RD-GLOBAL-B-2', $creator, $agentB);
        $this->createOverdueAttentionCase('RD-GLOBAL-B-3', $creator, $agentB);
        $unassignedCase = $this->createUnassignedAttentionCase('RD-GLOBAL-UNASSIGNED', $creator);

        $attentionCount = DashboardSnapshot::load()->incidentsForQueue('attention')->count();

        $this->assertSame(6, $attentionCount);

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'attention']))
            ->assertOk()
            ->assertSee('RD-GLOBAL-A-1')
            ->assertSee('RD-GLOBAL-B-3')
            ->assertSee('RD-GLOBAL-UNASSIGNED')
            ->assertSee($unassignedCase->reference_no);

        Carbon::setTestNow();
    }

    public function test_my_work_quick_search_filters_by_order_id(): void
    {
        $agent = $this->createAgent('Search Agent');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $match = $this->createIncident('RD-SEARCH-ME', $creator, $agent);
        $this->createIncident('RD-OTHER', $creator, $agent);

        $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.load-more', [
                'queue' => DashboardPersonalizationService::QUEUE_MY_WORK,
                'q' => 'RD-SEARCH-ME',
            ]))
            ->assertOk()
            ->assertJsonPath('total_count', 1)
            ->assertJsonCount(1, 'rows')
            ->assertSee('RD-SEARCH-ME', false);
    }

    public function test_agent_done_queue_is_scoped_to_assigned_completed_cases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createAgent('Done Agent A');
        $agentB = $this->createAgent('Done Agent B');
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $mineRecent = $this->createCompletedIncident('RD-DONE-A-1', $creator, $agentA, now());
        $mineOlder = $this->createCompletedIncident('RD-DONE-A-2', $creator, $agentA, now()->subWeek());
        $otherAgent = $this->createCompletedIncident('RD-DONE-B-1', $creator, $agentB, now()->subDay());
        $unassigned = $this->createCompletedIncident('RD-DONE-UNASSIGNED', $creator, null, now()->subDay());

        $personalization = app(DashboardPersonalizationService::class);

        $this->assertSame(
            $agentA->id,
            $personalization->resolveAssignedToScope($agentA, DashboardPersonalizationService::QUEUE_COMPLETED)?->id,
        );

        $completedForAgentA = DashboardSnapshot::load()->incidentsForQueue('completed', $agentA);

        $this->assertCount(2, $completedForAgentA);
        $this->assertTrue($completedForAgentA->contains(fn (Incident $case): bool => $case->id === $mineRecent->id));
        $this->assertTrue($completedForAgentA->contains(fn (Incident $case): bool => $case->id === $mineOlder->id));
        $this->assertFalse($completedForAgentA->contains(fn (Incident $case): bool => $case->id === $otherAgent->id));
        $this->assertFalse($completedForAgentA->contains(fn (Incident $case): bool => $case->id === $unassigned->id));

        $filterCounts = app(DashboardService::class)->serviceCaseFilterCounts($agentA, $agentA);

        $this->assertSame(2, $filterCounts['completed']);

        $this->actingAs($agentA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-case-filter-chip__label">Done<', false)
            ->assertSee('data-dashboard-case-filter-count="completed">2<', false);

        $this->actingAs($agentA)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_COMPLETED]))
            ->assertOk()
            ->assertSee('RD-DONE-A-1')
            ->assertSee('RD-DONE-A-2')
            ->assertDontSee('RD-DONE-B-1')
            ->assertDontSee('RD-DONE-UNASSIGNED');

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertNull(
            $personalization->resolveAssignedToScope($admin, DashboardPersonalizationService::QUEUE_COMPLETED),
        );
        $this->assertSame(4, DashboardSnapshot::load()->incidentsForQueue('completed')->count());

        Carbon::setTestNow();
    }

    private function createCompletedIncident(
        string $orderId,
        User $creator,
        ?User $assignee,
        ?Carbon $completedAt = null,
    ): Incident {
        $incident = $this->createIncident($orderId, $creator, $assignee);

        $incident->order?->update([
            'transaction_id' => 'TX-'.$orderId,
            'completed_at' => $completedAt ?? now(),
        ]);

        return $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
    }

    private function createOverdueAttentionCase(string $orderId, User $creator, User $assignee): Incident
    {
        $incident = $this->createIncident($orderId, $creator, $assignee);
        $incident->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        return $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
    }

    private function createUnassignedAttentionCase(string $orderId, User $creator): Incident
    {
        $incident = $this->createIncident($orderId, $creator, null);
        $incident->forceFill([
            'high_priority' => true,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ])->save();

        return $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
    }

    private function createAssignedWaitingCase(string $orderId, User $creator, ?User $assignee): Incident
    {
        $incident = $this->createIncident($orderId, $creator, $assignee);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        return $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
    }

    private function createAgent(string $name): User
    {
        $agent = User::factory()->create(['name' => $name]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
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
            'title' => 'Agent dashboard ownership test',
            'description' => 'Agent dashboard ownership test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
