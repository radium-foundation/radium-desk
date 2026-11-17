<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Events\Dashboard\HardwareStuckOrdersUpdated;
use App\Jobs\HardwareFulfilment\RefreshHardwareStuckOrdersSnapshotJob;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use App\Services\HardwareFulfilment\HardwareStuckOrdersSnapshotRefresher;
use App\Services\HardwareFulfilment\HardwareStuckOrdersSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareStuckOrdersSnapshotSwrTest extends TestCase
{
    use CreatesHardwareDashboardFulfilments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seedCatalog();
        $this->withoutVite();
    }

    public function test_initial_dashboard_renders_cached_snapshot_without_live_aggregation(): void
    {
        $this->fulfilment('RDE-SWR-01', HardwareFulfilmentState::ReadyForFulfilment);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $summary = app(HardwareDashboardWorkspace::class)->stuckOrderSummary();
        app(HardwareStuckOrdersSnapshotStore::class)->storeSuccessfulRefresh($summary);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $stats = app(DashboardService::class)->statsForInitialDashboardRender($admin);
        $ssrHwQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'hardware_fulfilments'))
            ->count();
        DB::disableQueryLog();

        $this->assertArrayHasKey('hardware_stuck_orders', $stats);
        $this->assertSame(0, $ssrHwQueries);

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'action_required']))
            ->assertOk()
            ->assertSee('data-hardware-stuck-loaded', false)
            ->assertDontSee('data-hardware-stuck-summary', false)
            ->assertSee('admin-hardware-stuck-chip', false);
    }

    public function test_stuck_summary_returns_stale_snapshot_and_queues_background_refresh(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $summary = ['needs_action_total' => 3, 'breakdown' => []];
        $store = app(HardwareStuckOrdersSnapshotStore::class);
        $version = $store->storeSuccessfulRefresh($summary);
        Cache::put('hardware.dashboard.stuck_summary.snapshot', [
            'summary' => $summary,
            'captured_at' => now()->subMinutes(5)->getTimestamp(),
            'version' => $version,
        ], 3600);

        Bus::fake();

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.stuck-summary'))
            ->assertOk()
            ->assertJsonPath('summary.needs_action_total', 3)
            ->assertJsonPath('stale', true);

        $this->assertTrue($response->json('refresh_queued'));
        Bus::assertDispatched(RefreshHardwareStuckOrdersSnapshotJob::class);
    }

    public function test_failed_refresh_keeps_last_known_snapshot(): void
    {
        $store = app(HardwareStuckOrdersSnapshotStore::class);
        $store->storeSuccessfulRefresh([
            'needs_action_total' => 5,
            'breakdown' => [],
        ]);

        Cache::forget('hardware.dashboard.stuck_summary.snapshot');

        $presentation = $store->presentationForRender();
        $this->assertNotNull($presentation);
        $this->assertSame(5, $presentation['summary']['needs_action_total']);
        $this->assertTrue($presentation['stale']);
    }

    public function test_refresh_job_publishes_snapshot_and_event(): void
    {
        $this->fulfilment('RDE-SWR-02', HardwareFulfilmentState::ReadyForFulfilment);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        Event::fake([HardwareStuckOrdersUpdated::class]);

        $result = app(HardwareStuckOrdersSnapshotRefresher::class)->refreshAndBroadcast();
        $this->assertTrue($result['refreshed']);

        Event::assertDispatched(HardwareStuckOrdersUpdated::class, function (HardwareStuckOrdersUpdated $event) use ($admin): bool {
            return $event->recipient->is($admin)
                && $event->version > 0
                && $event->summary['needs_action_total'] >= 1;
        });
    }

    public function test_agent_cannot_access_cached_stuck_summary(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.hardware.stuck-summary'))
            ->assertForbidden();
    }
}
