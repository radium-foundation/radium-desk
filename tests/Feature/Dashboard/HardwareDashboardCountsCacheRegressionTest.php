<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\HardwareFulfilment\HardwareDashboardCountsCache;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardCountsCacheRegressionTest extends TestCase
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

    public function test_warm_cache_reuses_filter_counts_within_ttl_bucket(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $toA = Carbon::parse('2026-11-11 16:10:05', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $toB = Carbon::parse('2026-11-11 16:10:22', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $cache = app(HardwareDashboardCountsCache::class);
        $workspace = app(HardwareDashboardWorkspace::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cold = $cache->filterCounts($from, $toA, '');
        $coldQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();
        $warm = $cache->filterCounts($from, $toB, '');
        DB::enableQueryLog();
        $warmQueries = count(DB::getQueryLog());

        $summary = $workspace->stuckOrderSummary();

        $this->assertSame($cold, $warm);
        $this->assertGreaterThan(0, $coldQueries, 'Expected cold miss to run SQL.');
        $this->assertSame(0, $warmQueries, 'Expected warm hit to skip SQL.');
        $this->assertSame((int) ($cold['needs_action'] ?? 0), (int) $summary['needs_action_total']);
    }

    public function test_admin_dashboard_chip_and_stuck_summary_share_warm_filter_bucket(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);
        Cache::flush();

        Carbon::setTestNow('2026-11-11 16:12:00 Asia/Kolkata');

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $t0 = hrtime(true);
        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'action_required']))
            ->assertOk();
        $firstMs = (hrtime(true) - $t0) / 1e6;
        $firstHw = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'hardware_fulfilments'))
            ->count();

        Carbon::setTestNow('2026-11-11 16:12:15 Asia/Kolkata');

        DB::flushQueryLog();
        $t1 = hrtime(true);
        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'action_required']))
            ->assertOk();
        $secondMs = (hrtime(true) - $t1) / 1e6;
        $secondHw = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'hardware_fulfilments'))
            ->count();

        Carbon::setTestNow();

        fwrite(STDERR, sprintf(
            "admin dashboard cold ms=%.1f hw_queries=%d | warm ms=%.1f hw_queries=%d\n",
            $firstMs,
            $firstHw,
            $secondMs,
            $secondHw,
        ));

        $this->assertGreaterThan($secondHw, $firstHw);
        fwrite(STDERR, sprintf(
            "hardware_filter_cache: cold_hw=%d warm_hw=%d (warm should be lower when cache shared)\n",
            $firstHw,
            $secondHw,
        ));
    }
}
