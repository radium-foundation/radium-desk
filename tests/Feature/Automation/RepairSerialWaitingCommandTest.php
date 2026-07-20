<?php

namespace Tests\Feature\Automation;

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
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\SettingService;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RepairSerialWaitingCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_dry_run_reports_eligible_incident_without_clearing_waiting(): void
    {
        [$admin, $incident] = $this->createStuckValidatedCase();

        $this->artisan('incidents:repair-serial-waiting', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('repaired: 1')
            ->expectsOutputToContain('skipped: 0')
            ->assertSuccessful();

        $fresh = $this->freshIncident($incident);
        $this->assertNotNull($fresh->activeWaitingState);
        $this->assertSame(
            OperationQueue::WaitingCustomer,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
        $this->assertNotNull($admin);
    }

    public function test_command_repairs_stuck_serial_waiting_and_enters_ready_queue(): void
    {
        Log::spy();

        [$admin, $incident, $order] = $this->createStuckValidatedCase();

        app(SettingService::class)->setMany([
            'assignment.day_shift_admin_user_id' => (string) $admin->id,
            'assignment.night_shift_admin_user_id' => (string) $admin->id,
        ]);

        $this->artisan('incidents:repair-serial-waiting')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('repaired: 1')
            ->expectsOutputToContain('skipped: 0')
            ->assertSuccessful();

        app(DashboardSnapshotStore::class)->forget();
        $fresh = $this->freshIncident($incident);

        $this->assertNull($fresh->activeWaitingState);
        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)
                ->isReadyForReferenceEntry($order->fresh(), $fresh),
        );
        $this->assertSame(
            OperationQueue::ActionRequired,
            app(OperationsQueueClassifier::class)->classify($fresh),
        );
        $this->assertTrue(
            DashboardSnapshot::load()
                ->incidentsForQueue(DashboardPersonalizationService::QUEUE_ACTION_REQUIRED)
                ->contains(fn (Incident $case): bool => $case->id === $incident->id),
        );

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Serial waiting repair repaired incident.'
                    && ($context['order_id'] ?? null) === 'RD-SERIAL-WAIT-REPAIR'
                    && ($context['queue'] ?? null) === OperationQueue::ActionRequired->value;
            })
            ->once();
    }

    public function test_command_skips_when_validation_fails_and_is_idempotent_after_repair(): void
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $invalidOrder = Order::query()->create([
            'order_id' => 'RD-SERIAL-WAIT-SKIP',
            'serial_number' => null,
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($invalidOrder->id);

        $invalidIncident = Incident::query()->create([
            'order_id' => $invalidOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Still waiting for serial',
            'description' => 'No serial yet.',
            'status' => IncidentStatus::Open,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        app(IncidentWaitingStateService::class)->start(
            incident: $invalidIncident,
            reason: WaitingReason::SerialNumber,
            actor: $admin,
        );

        [, $repairable] = $this->createStuckValidatedCase(orderId: 'RD-SERIAL-WAIT-REPAIR-2');

        $this->artisan('incidents:repair-serial-waiting')
            ->expectsOutputToContain('scanned: 2')
            ->expectsOutputToContain('repaired: 1')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();

        $this->assertNotNull($this->freshIncident($invalidIncident)->activeWaitingState);
        $this->assertNull($this->freshIncident($repairable)->activeWaitingState);

        $this->artisan('incidents:repair-serial-waiting')
            ->expectsOutputToContain('scanned: 1')
            ->expectsOutputToContain('repaired: 0')
            ->expectsOutputToContain('skipped: 1')
            ->assertSuccessful();
    }

    /**
     * @return array{0: User, 1: Incident, 2: Order}
     */
    private function createStuckValidatedCase(string $orderId = 'RD-SERIAL-WAIT-REPAIR'): array
    {
        $admin = $this->adminUser();
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => '7881953',
            'device_model' => $deviceModel->name,
            'product_name' => $deviceModel->name,
            'device_model_id' => $deviceModel->id,
            'status' => 'active',
            'created_by' => $admin->id,
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
        ]);
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => "Stuck serial waiting {$orderId}",
            'description' => 'Validated order still waiting on serial.',
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

        $this->assertTrue(
            app(ServiceCaseAssignmentEligibilityService::class)->passesValidationForOrder($order->fresh()),
        );

        return [$admin, $incident, $order];
    }

    private function adminUser(): User
    {
        $admin = User::query()->where('email', 'admin@radium.local')->first();

        if ($admin === null) {
            $admin = User::factory()->create([
                'email' => 'admin@radium.local',
                'is_active' => true,
            ]);
            $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        if (User::query()->where('email', 'superadmin@radium.local')->doesntExist()) {
            User::factory()->create([
                'email' => 'superadmin@radium.local',
                'is_active' => true,
            ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        }

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
