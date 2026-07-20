<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\TransactionAssigned;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use App\Services\OrderTransactionService;
use Database\Seeders\RolePermissionSeeder;
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
    }

    public function test_ref_no_assign_defers_dashboard_broadcasts_until_after_commit(): void
    {
        Event::fake([TransactionAssigned::class, DashboardKpisUpdated::class]);

        [$admin, $viewer, $order, $incident] = $this->createAssignableCase();

        $maxTransactionLevelSeenDuringKpiDispatch = 0;

        Event::listen(DashboardKpisUpdated::class, function () use (&$maxTransactionLevelSeenDuringKpiDispatch): void {
            $maxTransactionLevelSeenDuringKpiDispatch = max(
                $maxTransactionLevelSeenDuringKpiDispatch,
                DB::transactionLevel(),
            );
        });

        Event::listen(TransactionAssigned::class, function () use (&$maxTransactionLevelSeenDuringKpiDispatch): void {
            $maxTransactionLevelSeenDuringKpiDispatch = max(
                $maxTransactionLevelSeenDuringKpiDispatch,
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
            $maxTransactionLevelSeenDuringKpiDispatch,
            'Dashboard broadcasts must not run while the DB transaction is open.',
        );
        $this->assertSame(IncidentStatus::Closed, $incident->fresh()->status);
        $this->assertSame('TXN-POST-COMMIT-1', $order->fresh()->transaction_id);

        Event::assertDispatched(TransactionAssigned::class);
        Event::assertDispatched(DashboardKpisUpdated::class, function (DashboardKpisUpdated $event) use ($viewer): bool {
            return $event->recipient->is($viewer);
        });
    }

    public function test_ref_no_assign_coalesces_kpi_refresh_to_one_per_viewer(): void
    {
        Event::fake([DashboardKpisUpdated::class, TransactionAssigned::class]);

        [$admin, $viewer, $order] = $this->createAssignableCase();

        app(OrderTransactionService::class)->assignTransactionId(
            order: $order,
            transactionId: 'TXN-KPI-ONCE',
            actor: $admin,
        );

        $viewerDispatches = Event::dispatched(DashboardKpisUpdated::class)
            ->filter(function (array $args) use ($viewer): bool {
                $event = $args[0] ?? null;

                return $event instanceof DashboardKpisUpdated && $event->recipient->is($viewer);
            });

        $this->assertCount(1, $viewerDispatches, 'Each viewer should receive a single coalesced KPI refresh.');
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
