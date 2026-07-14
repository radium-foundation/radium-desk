<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderDeviceModelService;
use App\Services\OrderIdentityRepairService;
use App\Services\OrderSerialService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ReadyQueueRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_serial_correction_immediately_enters_ready_queue(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-SERIAL-READY',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
        ]);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $classifier = app(OperationsQueueClassifier::class);
        $this->assertNotSame(
            OperationQueue::ActionRequired,
            $classifier->classify($this->freshIncident($incident)),
        );

        app(OrderSerialService::class)->assignSerialNumber($order, '7881953', $admin);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);
        $this->assertTrue(app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order->fresh(), $fresh));
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $i): bool => $i->id === $incident->id),
        );
    }

    public function test_device_model_assignment_immediately_enters_ready_queue(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-MODEL-READY',
            'serial_number' => '7881953',
            'device_model' => null,
            'product_name' => null,
            'device_model_id' => null,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertNotSame(
            OperationQueue::ActionRequired,
            $classifier->classify($this->freshIncident($incident)),
        );

        app(OrderDeviceModelService::class)->assignDeviceModel($order, $deviceModel, $admin);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
    }

    public function test_bulk_device_model_assignment_enters_ready_queue(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-BULK-MODEL-READY',
            'serial_number' => '7881954',
            'device_model' => null,
            'product_name' => null,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin, assignee: $admin);

        app(OrderDeviceModelService::class)->assignDeviceModelToIncidents([$incident->id], $deviceModel->id, $admin);

        app(DashboardSnapshotStore::class)->forget();

        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($this->freshIncident($incident)),
        );
    }

    public function test_identity_repair_enters_ready_queue(): void
    {
        Sleep::fake();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => 'B47C11929',
                        'product_name' => 'Access FM220 L1',
                    ],
                ],
            ]),
        ]);

        $actor = $this->adminUser();
        $order = $this->createOrder($actor, [
            'order_id' => 'RD-REPAIR-READY',
            'serial_number' => null,
            'device_model' => null,
            'product_name' => null,
        ]);
        $incident = $this->createIncident($order, $actor);

        $this->artisan('orders:repair-identity --force')->assertSuccessful();

        app(DashboardSnapshotStore::class)->forget();

        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($this->freshIncident($incident)),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => OrderIdentityRepairService::AUDIT_EVENT,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_ready_queue_is_independent_of_incident_age(): void
    {
        $admin = $this->adminUser();
        $order = $this->createValidatedOrder($admin, 'RD-STALE-VALID');
        $incident = $this->createIncident(
            $order,
            $admin,
            createdAt: now()->subHours(30),
        );

        $classifier = app(OperationsQueueClassifier::class);
        $fresh = $this->freshIncident($incident);

        $this->assertTrue($classifier->isStaleBacklog($fresh));
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));

        $counts = DashboardSnapshot::load()->queueCounts();
        $this->assertSame(1, $counts[DashboardPersonalizationService::QUEUE_ACTION_REQUIRED]);
        $this->assertSame(0, $counts[DashboardPersonalizationService::QUEUE_PENDING_REVIEW]);
    }

    public function test_ready_queue_is_independent_of_assignee(): void
    {
        $adminA = $this->adminUser();
        $adminB = $this->adminUser();

        $assignedOrder = $this->createValidatedOrder($adminA, 'RD-ASSIGNED-READY');
        $assignedIncident = $this->createIncident($assignedOrder, $adminA, assignee: $adminA);

        $unassignedOrder = $this->createValidatedOrder($adminA, 'RD-UNASSIGNED-READY');
        $unassignedIncident = $this->createIncident($unassignedOrder, $adminA, assignee: null);

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(
            OperationQueue::ActionRequired,
            $classifier->classify($this->freshIncident($assignedIncident)),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            $classifier->classify($this->freshIncident($unassignedIncident)),
        );

        app(DashboardSnapshotStore::class)->forget();
        $readyQueue = DashboardSnapshot::load()->incidentsForQueue(
            DashboardPersonalizationService::QUEUE_ACTION_REQUIRED,
        );

        $this->assertTrue($readyQueue->contains(fn (Incident $i): bool => $i->id === $assignedIncident->id));
        $this->assertTrue($readyQueue->contains(fn (Incident $i): bool => $i->id === $unassignedIncident->id));

        $scope = app(DashboardPersonalizationService::class)
            ->resolveAssignedToScope($adminB, DashboardPersonalizationService::QUEUE_ACTION_REQUIRED);
        $this->assertNull($scope);
    }

    public function test_inquiry_orders_are_excluded_from_ready_queue(): void
    {
        $admin = $this->adminUser();
        $order = $this->createOrder($admin, [
            'order_id' => 'INQ-12345',
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin);
        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        $this->assertFalse($eligibility->isReadyForReferenceEntry($order, $this->freshIncident($incident)));
        $this->assertNotSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($this->freshIncident($incident)),
        );
    }

    public function test_hardware_orders_are_excluded_from_ready_queue(): void
    {
        $admin = $this->adminUser();
        $order = $this->createOrder($admin, [
            'order_id' => 'RDE-HW-001',
            'serial_number' => 'B47C11929',
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin);

        $this->assertSame(
            OperationQueue::Hardware,
            app(OperationsQueueClassifier::class)->classify($this->freshIncident($incident)),
        );
    }

    public function test_closed_and_resolved_cases_are_excluded_from_ready_queue(): void
    {
        $admin = $this->adminUser();
        $order = $this->createValidatedOrder($admin, 'RD-CLOSED-READY');
        $closedIncident = $this->createIncident($order, $admin, status: IncidentStatus::Closed);
        $resolvedIncident = $this->createIncident(
            $this->createValidatedOrder($admin, 'RD-RESOLVED-READY'),
            $admin,
            status: IncidentStatus::Resolved,
        );

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(OperationQueue::Completed, $classifier->classify($this->freshIncident($closedIncident)));
        $this->assertSame(OperationQueue::Completed, $classifier->classify($this->freshIncident($resolvedIncident)));
    }

    public function test_waiting_customer_scheduled_and_exceptions_queues_remain_unchanged(): void
    {
        $admin = $this->adminUser();
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $waitingOrder = $this->createOrder($admin, ['order_id' => 'RD-WAIT-KEEP']);
        $waiting = $this->createIncident($waitingOrder, $admin, assignee: $agent);
        IncidentWaitingState::query()->create([
            'incident_id' => $waiting->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $admin->id,
        ]);

        $scheduledOrder = $this->createValidatedOrder($admin, 'RD-SCHED-KEEP');
        $scheduled = $this->createIncident($scheduledOrder, $admin, assignee: $agent);
        SupportAppointment::query()->create([
            'incident_id' => $scheduled->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => 'morning',
            'phone_number' => '9999999999',
        ]);

        $exceptionOrder = $this->createOrder($admin, [
            'order_id' => 'RD-EXCEPTION-KEEP',
            'serial_number' => '54SAXXC5514586',
            'device_model' => 'MFS110',
            'product_name' => 'MFS110',
        ]);
        $this->markSynced($exceptionOrder);
        $exception = $this->createIncident($exceptionOrder, $admin, assignee: $agent, highPriority: true);

        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(OperationQueue::WaitingCustomer, $classifier->classify($this->freshIncident($waiting)));
        $this->assertSame(OperationQueue::Scheduled, $classifier->classify($this->freshIncident($scheduled)));
        $this->assertSame(OperationQueue::Attention, $classifier->classify($this->freshIncident($exception)));
    }

    public function test_my_work_still_shows_assigned_in_progress_identity_work(): void
    {
        $admin = $this->adminUser();
        $order = $this->createOrder($admin, [
            'order_id' => 'RD-MYWORK-INPROGRESS',
            'serial_number' => null,
            'device_model' => 'Access FM220 L1',
            'product_name' => 'Access FM220 L1',
        ]);
        $incident = $this->createIncident($order, $admin, assignee: $admin);

        $classifier = app(OperationsQueueClassifier::class);
        $fresh = $this->freshIncident($incident);

        $this->assertNotSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
        $this->assertTrue($classifier->matchesMyWork($fresh, $admin));

        $myWork = DashboardSnapshot::load()->incidentsForQueue('my_work', $admin);
        $this->assertTrue($myWork->contains(fn (Incident $i): bool => $i->id === $incident->id));
    }

    public function test_has_model_identity_recognises_device_model_id(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-MODEL-ID-ONLY',
            'serial_number' => '7881955',
            'device_model' => null,
            'product_name' => null,
            'device_model_id' => $deviceModel->id,
        ]);
        $this->markSynced($order);

        $incident = $this->createIncident($order, $admin);

        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->isReadyForReferenceEntry($order, $this->freshIncident($incident)),
        );
    }

    public function test_ready_queue_independent_of_sla(): void
    {
        $admin = $this->adminUser();
        $order = $this->createValidatedOrder($admin, 'RD-SLA-OVERDUE-READY');
        $incident = $this->createIncident(
            $order,
            $admin,
            assignee: $admin,
            createdAt: Carbon::parse('2026-07-12 10:00:00', 'Asia/Kolkata'),
        );

        $fresh = $this->freshIncident($incident);
        $classifier = app(OperationsQueueClassifier::class);

        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
        $this->assertFalse($classifier->isAttention($fresh));
        $this->assertTrue(
            DashboardSnapshot::load()->incidentsForFilter('overdue', null)->contains(fn (Incident $i): bool => $i->id === $incident->id),
        );
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
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
        ]);
        $this->markSynced($order);

        return $order;
    }

    private function createIncident(
        Order $order,
        User $creator,
        ?User $assignee = null,
        ?Carbon $createdAt = null,
        IncidentStatus $status = IncidentStatus::Open,
        bool $highPriority = false,
    ): Incident {
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Case {$order->order_id}",
            'description' => "Case {$order->order_id}.",
            'status' => $status,
            'high_priority' => $highPriority,
            'assigned_to_user_id' => $assignee?->id,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        if ($createdAt !== null) {
            $incident->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        return $incident;
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
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);
    }
}
