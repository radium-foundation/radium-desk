<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ReferenceNumbersUpdated;
use App\Events\Dashboard\TransactionAssigned;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\OrderTransactionService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderTransactionPostCommitStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_ref_no_assign_with_feature_off_skips_realtime_broadcasts(): void
    {
        Event::fake([ReferenceNumbersUpdated::class, TransactionAssigned::class, DashboardKpisUpdated::class]);

        [$admin, , $order, $incident] = $this->createAssignableCase();

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-POST-COMMIT-OFF',
            actor: $admin,
        );

        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame('TXN-POST-COMMIT-OFF', $order->fresh()->transaction_id);

        Event::assertNotDispatched(ReferenceNumbersUpdated::class);
        Event::assertNotDispatched(TransactionAssigned::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_ref_no_assign_defers_hybrid_broadcast_until_after_commit(): void
    {
        Event::fake([ReferenceNumbersUpdated::class, TransactionAssigned::class, DashboardKpisUpdated::class]);
        app(SystemSettingsService::class)->set('hybrid_realtime.reference_number', true);

        [$admin, $viewer, $order, $incident] = $this->createAssignableCase();

        $maxTransactionLevelSeenDuringDispatch = 0;

        Event::listen(ReferenceNumbersUpdated::class, function () use (&$maxTransactionLevelSeenDuringDispatch): void {
            $maxTransactionLevelSeenDuringDispatch = max(
                $maxTransactionLevelSeenDuringDispatch,
                DB::transactionLevel(),
            );
        });

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-POST-COMMIT-1',
            actor: $admin,
        );

        $this->assertSame(
            0,
            $maxTransactionLevelSeenDuringDispatch,
            'Dashboard broadcasts must not run while the DB transaction is open.',
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame('TXN-POST-COMMIT-1', $order->fresh()->transaction_id);

        Event::assertDispatched(ReferenceNumbersUpdated::class, function (ReferenceNumbersUpdated $event) use ($viewer, $incident): bool {
            return $event->recipient->is($viewer)
                && in_array($incident->id, $event->broadcastWith()['incident_ids'], true);
        });
        Event::assertNotDispatched(TransactionAssigned::class);
        Event::assertNotDispatched(DashboardKpisUpdated::class);
    }

    public function test_activity_touches_do_not_run_inside_open_transaction(): void
    {
        [$admin, , $order] = $this->createAssignableCase();

        $usersUpdatedDuringTransaction = false;

        DB::listen(function (object $query) use (&$usersUpdatedDuringTransaction): void {
            $sql = strtolower($query->sql);

            if (DB::transactionLevel() > 0
                && str_contains($sql, 'update `users`')
                && (str_contains($sql, 'last_case_action_at') || str_contains($sql, 'last_status_change_at'))) {
                $usersUpdatedDuringTransaction = true;
            }
        });

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-ACTIVITY-AFTER',
            actor: $admin,
        );

        $this->assertFalse(
            $usersUpdatedDuringTransaction,
            'Team activity user touches must run after the assign transaction commits.',
        );

        $this->assertNotNull($admin->fresh()->last_case_action_at);
    }

    public function test_kpi_coalesce_flush_dispatches_once(): void
    {
        Event::fake([DashboardKpisUpdated::class]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $broadcasts = app(DashboardBroadcastService::class);
        $broadcasts->beginKpiCoalesce($admin);
        $broadcasts->kpisUpdated($admin);
        $broadcasts->kpisUpdated($admin);
        $broadcasts->kpisUpdated($admin);
        $broadcasts->flushKpiCoalesce();

        Event::assertDispatchedTimes(DashboardKpisUpdated::class, 1);
        $this->assertTrue($viewer->is_active);
    }

    /**
     * @return array{0: User, 1: User, 2: Order, 3: Incident}
     */
    private function createAssignableCase(): array
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-POST-COMMIT-'.uniqid(),
            'serial_number' => '7881001',
            'product_name' => 'MFS110',
            'device_model' => 'MFS110',
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('orders.legacy-verification.store', $order), [
                'confirmed' => true,
            ])
            ->assertOk();

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-POST-'.uniqid(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Post-commit stability case',
            'description' => 'Post-commit stability case',
            'status' => IncidentStatus::InProgress,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'assigned_to_user_id' => $admin->id,
        ]);

        return [$admin, $viewer, $order->fresh(), $incident];
    }
}
