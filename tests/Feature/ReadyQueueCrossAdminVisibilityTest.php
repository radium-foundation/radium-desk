<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadyQueueCrossAdminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_action_required_queue_shows_cases_assigned_to_other_admins(): void
    {
        $adminA = User::factory()->create(['name' => 'Admin A']);
        $adminA->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $adminB = User::factory()->create(['name' => 'Admin B']);
        $adminB->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAwaitingProductDetailsCase('RD3450731', $adminA, $adminA);
        $this->createAwaitingProductDetailsCase('RD3449573', $adminA, $adminA);
        $this->createAwaitingProductDetailsCase('RD3449136', $adminA, $adminA);

        $snapshot = DashboardSnapshot::load();
        $personalization = app(DashboardPersonalizationService::class);
        $assignedToScope = $personalization->resolveAssignedToScope($adminB, DashboardPersonalizationService::QUEUE_ACTION_REQUIRED);

        $this->assertNull($assignedToScope);
        $this->assertSame(3, $snapshot->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)->count());
        $this->assertSame(3, $snapshot->incidentsForFilter('pending_admin', null)->count());
        $this->assertSame(3, $snapshot->incidentsForFilter('pending_admin', $adminB)->count());
        $this->assertSame(3, $snapshot->incidentsForFilter('action_required', $adminB)->count());
        $this->assertSame(3, app(DashboardService::class)->recentServiceCases(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            limit: 10,
            assignedTo: $assignedToScope,
        )->count());

        $this->actingAs($adminB)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk()
            ->assertSee('RD3450731')
            ->assertSee('RD3449573')
            ->assertSee('RD3449136');

        $this->actingAs($adminB)
            ->getJson(route('dashboard.live', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk()
            ->assertJsonCount(3, 'rows');

        $scopedCounts = app(DashboardService::class)->serviceCaseFilterCounts($adminB, $adminB);

        $this->assertSame(3, $scopedCounts[DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]);
        $this->assertSame(3, $scopedCounts['pending_admin']);
    }

    public function test_agent_pending_admin_filter_remains_scoped_to_assignee(): void
    {
        $agentA = User::factory()->create(['name' => 'Agent A']);
        $agentA->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $agentB = User::factory()->create(['name' => 'Agent B']);
        $agentB->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createOpenAssignedCase('RD-AGENT-A', $creator, $agentA);
        $this->createOpenAssignedCase('RD-AGENT-B', $creator, $agentB);

        $snapshot = DashboardSnapshot::load();

        $this->assertSame(1, $snapshot->incidentsForFilter('pending_admin', $agentA)->count());
        $this->assertSame(1, $snapshot->incidentsForFilter('pending_admin', $agentB)->count());
    }

    public function test_my_work_queue_remains_scoped_to_assignee(): void
    {
        $adminA = User::factory()->create(['name' => 'Admin A']);
        $adminA->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $adminB = User::factory()->create(['name' => 'Admin B']);
        $adminB->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAwaitingProductDetailsCase('RD-MYWORK-A', $adminA, $adminA);
        $this->createAwaitingProductDetailsCase('RD-MYWORK-B', $adminB, $adminB);

        $snapshot = DashboardSnapshot::load();

        $this->assertSame(1, $snapshot->incidentsForQueue('my_work', $adminA)->count());
        $this->assertSame(1, $snapshot->incidentsForQueue('my_work', $adminB)->count());
    }

    private function createAwaitingProductDetailsCase(string $orderId, User $creator, User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$orderId}",
            'description' => "Awaiting product details for {$orderId}.",
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        $freshIncident = $incident->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            $classifier->classify($freshIncident)->value,
        );
        $this->assertNull($freshIncident->activeWaitingState);

        return $freshIncident;
    }

    private function createOpenAssignedCase(string $orderId, User $creator, User $assignee): Incident
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
            'title' => "Case {$orderId}",
            'description' => "Case {$orderId}.",
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
