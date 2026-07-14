<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
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

class ReadyQueueSlaDecouplingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:14:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rd3449136_overdue_case_stays_in_ready_queue_with_overdue_badge(): void
    {
        $adminA = User::factory()->create(['name' => 'Admin A']);
        $adminA->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $adminB = User::factory()->create(['name' => 'Admin B']);
        $adminB->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $incident = $this->createAwaitingProductDetailsCase(
            orderId: 'RD3449136',
            assignee: $adminA,
            createdAt: Carbon::parse('2026-07-12 10:00:00', 'Asia/Kolkata'),
            serialNumber: '9614597',
        );

        $fresh = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(ServiceCaseSlaStatus::Overdue, $fresh->slaStatus());
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
        $this->assertFalse($classifier->isAttention($fresh));

        app(DashboardSnapshotStore::class)->forget();
        $snapshot = DashboardSnapshot::load();

        $this->assertTrue($snapshot->incidentsForQueue('action_required')->contains(fn (Incident $i): bool => $i->id === $fresh->id));
        $this->assertFalse($snapshot->incidentsForQueue('attention')->contains(fn (Incident $i): bool => $i->id === $fresh->id));
        $this->assertTrue($snapshot->incidentsForFilter('overdue', null)->contains(fn (Incident $i): bool => $i->id === $fresh->id));

        $this->actingAs($adminB)
            ->get(route('dashboard', ['queue' => DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]))
            ->assertOk()
            ->assertSee('RD3449136')
            ->assertSee('sla-status--overdue', false)
            ->assertSee('Overdue');

        $this->actingAs($adminB)
            ->get(route('dashboard', ['filter' => 'overdue']))
            ->assertOk()
            ->assertSee('RD3449136');
    }

    public function test_validation_failure_still_routes_to_exceptions_queue(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-VALIDATION-FAIL',
            'serial_number' => '54SAXXC5514586',
            'device_model' => 'MFS110',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'created_by' => $admin->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Validation failed case',
            'description' => 'Validation failed case.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $fresh = $incident->fresh(['order', 'assignee.roles', 'activeWaitingState', 'supportAppointments']);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(OperationQueue::Attention, $classifier->classify($fresh));

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'attention']))
            ->assertOk()
            ->assertSee('Exceptions')
            ->assertSee('RD-VALIDATION-FAIL');
    }

    public function test_waiting_scheduled_completed_and_my_work_remain_unchanged(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $waiting = $this->createAwaitingProductDetailsCase('RD-WAIT-KEEP', $agent, now()->subHour());
        IncidentWaitingState::query()->create([
            'incident_id' => $waiting->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $admin->id,
        ]);

        $scheduled = $this->createAwaitingProductDetailsCase('RD-SCHED-KEEP', $agent, now()->subHour(), '9614597');
        SupportAppointment::query()->create([
            'incident_id' => $scheduled->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => 'morning',
            'phone_number' => '9999999999',
        ]);

        $completed = $this->createAwaitingProductDetailsCase('RD-DONE-KEEP', $agent, now()->subHour(), '9614598');
        $completed->order?->update([
            'transaction_id' => 'TX-DONE-KEEP',
            'completed_at' => now(),
        ]);

        $ready = $this->createAwaitingProductDetailsCase('RD-READY-KEEP', $admin, now()->subHours(2), '7881953');

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(
            OperationQueue::WaitingCustomer,
            $classifier->classify($waiting->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );
        $this->assertSame(
            OperationQueue::Scheduled,
            $classifier->classify($scheduled->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );
        $this->assertSame(
            OperationQueue::Completed,
            $classifier->classify($completed->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            $classifier->classify($ready->fresh(['order', 'assignee', 'activeWaitingState', 'supportAppointments'])),
        );

        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $agent);
        $this->assertTrue($myWork->contains(fn (Incident $i): bool => $i->id === $waiting->id));
        $this->assertTrue($myWork->contains(fn (Incident $i): bool => $i->id === $scheduled->id));
        $this->assertFalse($myWork->contains(fn (Incident $i): bool => $i->id === $completed->id));

        $adminWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $admin);
        $this->assertTrue($adminWork->contains(fn (Incident $i): bool => $i->id === $ready->id));
    }

    public function test_admin_default_landing_is_ready_queue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $personalization = app(DashboardPersonalizationService::class);

        $this->assertSame(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            $personalization->defaultQueueFor($admin),
        );

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-live-queue="action_required"', false)
            ->assertSee('Exceptions')
            ->assertSee('Ready Queue');
    }

    public function test_overdue_kpi_links_to_overdue_filter_not_exceptions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAwaitingProductDetailsCase(
            'RD-KPI-OVERDUE',
            $admin,
            Carbon::parse('2026-07-12 10:00:00', 'Asia/Kolkata'),
        );

        $expectedHref = route('dashboard', ['filter' => 'overdue']).'#dashboard-service-cases-panel';

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($expectedHref, false)
            ->assertDontSee(route('dashboard', ['queue' => 'attention']).'#dashboard-service-cases-panel', false);
    }

    public function test_cross_admin_ready_queue_still_shows_assigned_cases(): void
    {
        $adminA = User::factory()->create();
        $adminA->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $adminB = User::factory()->create();
        $adminB->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->createAwaitingProductDetailsCase('RD3450731', $adminA, now()->subHours(2), '7881956');
        $this->createAwaitingProductDetailsCase(
            'RD3449136',
            $adminA,
            Carbon::parse('2026-07-12 10:00:00', 'Asia/Kolkata'),
            '7881957',
        );

        $scope = app(DashboardPersonalizationService::class)
            ->resolveAssignedToScope($adminB, DashboardPersonalizationService::QUEUE_ACTION_REQUIRED);

        $rows = app(DashboardService::class)->recentServiceCases(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
            limit: 20,
            assignedTo: $scope,
        );

        $this->assertNull($scope);
        $this->assertTrue($rows->contains(fn (Incident $i): bool => $i->order?->order_id === 'RD3450731'));
        $this->assertTrue($rows->contains(fn (Incident $i): bool => $i->order?->order_id === 'RD3449136'));
    }

    private function createAwaitingProductDetailsCase(
        string $orderId,
        User $assignee,
        Carbon $createdAt,
        ?string $serialNumber = null,
    ): Incident {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => $serialNumber,
            'product_name' => $serialNumber !== null ? 'MFS110' : 'MFS 110',
            'device_model' => $serialNumber !== null ? 'MFS110' : 'MFS 110',
            'status' => 'active',
            'created_by' => $assignee->id,
        ]);

        if ($serialNumber !== null) {
            app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);
        }

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$orderId}",
            'description' => "Awaiting product details for {$orderId}.",
            'status' => IncidentStatus::AwaitingProductDetails,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $assignee->id,
            'updated_by' => $assignee->id,
        ]);

        $incident->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $incident;
    }
}
