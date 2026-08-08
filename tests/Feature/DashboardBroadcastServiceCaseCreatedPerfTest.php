<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\OrderStatus;
use App\Events\Dashboard\DashboardKpisUpdated;
use App\Events\Dashboard\ServiceCaseCreated;
use App\Events\Dashboard\SlaStatusChanged;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Local microbench for P0-1 dashboard_broadcast KPI path.
 *
 * Before (production evidence / old path): serviceCaseCreated called kpisUpdated twice
 * (create + SLA) → liveReverbMetricsFor × recipients ≈ 25–38s wall / ~24 Ably KPI publishes.
 *
 * After: serviceCaseCreated does not dispatch DashboardKpisUpdated; clients refresh via
 * hybrid-kpi-reconcile / live poll after ServiceCaseCreated / SlaStatusChanged.
 */
class DashboardBroadcastServiceCaseCreatedPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_service_case_created_avoids_sync_kpi_fanout_under_twelve_viewers(): void
    {
        Event::fake([ServiceCaseCreated::class, SlaStatusChanged::class, DashboardKpisUpdated::class]);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewerCount = 12;
        for ($i = 0; $i < $viewerCount; $i++) {
            $viewer = User::factory()->create(['is_active' => true]);
            $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $order = Order::query()->create([
            'order_id' => 'ORD-PERF-CREATE-1',
            'serial_number' => 'SN-PERF-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'cashfree_payment_id' => 'cf_perf_create_1',
            'status' => OrderStatus::Active,
            'created_by' => $actor->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-PERF-CREATE-1',
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Perf create',
            'description' => 'Perf create',
            'status' => 'open',
            'created_by' => $actor->id,
        ]);

        $started = hrtime(true);
        app(DashboardBroadcastService::class)->serviceCaseCreated($incident->fresh(), $actor);
        $wallMs = (hrtime(true) - $started) / 1_000_000;

        Event::assertNotDispatched(DashboardKpisUpdated::class);
        Event::assertDispatchedTimes(ServiceCaseCreated::class, $viewerCount);
        Event::assertDispatchedTimes(SlaStatusChanged::class, $viewerCount);

        // Row HTML × recipients remains; keep a loose local ceiling so regressions that
        // re-introduce KPI fanout fail loudly (production was 25–38s).
        $this->assertLessThan(5_000, $wallMs, sprintf('serviceCaseCreated wall_ms=%.1f exceeded 5s ceiling', $wallMs));

        fwrite(STDOUT, sprintf(
            "P0-1 AFTER serviceCaseCreated: wall_ms=%.1f viewers=%d row_events=%d sla_events=%d kpi_events=0\n",
            $wallMs,
            $viewerCount,
            $viewerCount,
            $viewerCount,
        ));
    }

    public function test_legacy_kpis_updated_still_fanouts_to_all_viewers(): void
    {
        Event::fake([DashboardKpisUpdated::class]);

        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $viewerCount = 12;
        for ($i = 0; $i < $viewerCount; $i++) {
            $viewer = User::factory()->create(['is_active' => true]);
            $viewer->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        }

        $started = hrtime(true);
        app(DashboardBroadcastService::class)->kpisUpdated($actor);
        $wallMs = (hrtime(true) - $started) / 1_000_000;

        Event::assertDispatchedTimes(DashboardKpisUpdated::class, $viewerCount);

        fwrite(STDOUT, sprintf(
            "P0-1 BEFORE-equivalent kpisUpdated×1: wall_ms=%.1f kpi_events=%d (create path previously ran this twice via SLA)\n",
            $wallMs,
            $viewerCount,
        ));
    }
}
