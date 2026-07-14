<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\IncidentReferenceService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderIdentityLifecycleService;
use App\Services\OrderSerialService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class IdentityLifecycleHardeningTest extends TestCase
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
    }

    public function test_unassigned_pass_severity_enters_ready_queue_after_serial_correction(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-UNASSIGNED-PASS',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
        ]);

        $incident = $this->createIncident($order, $admin, assignee: null);
        $classifier = app(OperationsQueueClassifier::class);

        app(OrderSerialService::class)->assignSerialNumber($order, '7881953', $admin);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);
        $eligibility = app(ServiceCaseAssignmentEligibilityService::class);

        $this->assertTrue($eligibility->isReadyForReferenceEntry($order->fresh(), $fresh));
        $this->assertSame(OperationQueue::ActionRequired, $classifier->classify($fresh));
    }

    public function test_workspace_enrichment_runs_identity_lifecycle_for_unassigned_pass_severity(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS110',
                    ],
                ],
            ]),
        ]);

        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-WORKSPACE-ENRICH',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
        ]);

        $incident = $this->createIncident($order, $admin, assignee: null);

        app(RadiumBoxService::class)->enrichOrderForWorkspace($order);

        app(DashboardSnapshotStore::class)->forget();

        $fresh = $this->freshIncident($incident);

        $this->assertSame('7881953', $order->fresh()->serial_number);
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
    }

    public function test_background_enrichment_runs_identity_lifecycle_once_when_identity_fields_apply(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'serial_no' => '7881953',
                        'product_name' => 'MFS110',
                    ],
                ],
            ]),
        ]);

        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = $this->createOrder($admin, [
            'order_id' => 'RD-BG-LIFECYCLE',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
        ]);

        $this->createIncident($order, $admin, assignee: null);

        $lifecycleCalls = 0;
        $lifecycle = Mockery::mock(OrderIdentityLifecycleService::class)->makePartial();
        $lifecycle->shouldReceive('afterIdentityChanged')
            ->andReturnUsing(function () use (&$lifecycleCalls): void {
                $lifecycleCalls++;
            });
        $this->app->instance(OrderIdentityLifecycleService::class, $lifecycle);

        app(RadiumBoxOrderEnrichmentService::class)->process($order->id, 1);

        $this->assertSame(1, $lifecycleCalls);
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
            'updated_by' => $creator->id,
            ...$overrides,
        ]);
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
            'source' => IncidentSource::Cashfree,
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
}
