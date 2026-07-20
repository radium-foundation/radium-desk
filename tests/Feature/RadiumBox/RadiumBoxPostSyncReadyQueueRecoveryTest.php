<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardPersonalizationService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\OrderIdentityLifecycleService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxPostSyncReadyQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_pending_serial_enrichment_recovers_waiting_and_enters_ready_queue_after_synced(): void
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
        app(SettingService::class)->setMany([
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
        ]);

        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-POSTSYNC-READY',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Post-sync Ready recovery case',
            'description' => 'Waiting for serial before Ready Queue recovery.',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => null,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markPending($order->id);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $syncStore->status($order->id));

        // Reproduce the production race: serial applied while sync is still PENDING.
        $order->update(['serial_number' => '7881953']);
        app(OrderIdentityLifecycleService::class)->afterIdentityChanged(
            order: $order->fresh(),
            actor: $admin,
            source: 'radiumbox_enrichment',
            serialChanged: true,
        );

        $stuck = $this->freshIncident($incident);
        $this->assertNotNull($stuck->activeWaitingState);
        $this->assertSame(WaitingReason::SerialNumber, $stuck->activeWaitingState->waiting_reason);
        $this->assertSame(
            OperationQueue::WaitingCustomer,
            app(OperationsQueueClassifier::class)->classify($stuck),
        );
        $this->assertFalse(
            app(ServiceCaseAssignmentEligibilityService::class)
                ->passesValidationForOrder($order->fresh()),
            'Validation must still fail while RadiumBox sync is PENDING.',
        );

        // Completing markSynced via enrichment process must recover waiting + Ready.
        app(RadiumBoxOrderEnrichmentService::class)->process($order->id, attempt: 1);

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $syncStore->status($order->id));

        app(DashboardSnapshotStore::class)->forget();
        $recovered = $this->freshIncident($incident);

        $this->assertNull($recovered->activeWaitingState);
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)
                ->isReadyForReferenceEntry($order->fresh(), $recovered),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($recovered),
        );
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );

        $assigneeAfterRecovery = $recovered->assigned_to_user_id;

        // Idempotent: a second synced pass must not re-open waiting or churn assignment.
        app(RadiumBoxOrderEnrichmentService::class)->process($order->id, attempt: 2);

        $again = $this->freshIncident($incident);
        $this->assertNull($again->activeWaitingState);
        $this->assertSame(OperationQueue::ActionRequired, app(OperationsQueueClassifier::class)->classify($again));
        $this->assertSame($assigneeAfterRecovery, $again->assigned_to_user_id);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
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
