<?php

namespace Tests\Feature\Platform;

use App\Services\Platform\Warmers\PlatformSnapshotWarmingService;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the platform:snapshots:warm CPU path.
 *
 * Cold warm must not rebuild executive KPIs 8× or double-probe platform health.
 * Second warm within TTL must skip rebuilds (incremental warming).
 */
class PlatformSnapshotWarmPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_cold_warm_stays_under_query_budget_and_dedupes_executive_context(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = hrtime(true);
        $result = app(PlatformSnapshotWarmingService::class)->warmAll();
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        $queries = collect(DB::getQueryLog());
        $queryCount = $queries->count();

        $openCasesAggregates = $queries
            ->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'open_cases')
                && str_contains(strtolower($q['query']), 'critical_cases'))
            ->count();

        $this->assertSame([], $result['failed']);
        $this->assertContains('platform_health', $result['warmed']);
        $this->assertContains('executive_snapshot', $result['warmed']);
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('platform_health'));
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('executive_snapshot'));

        // Before: 8× context builds (one per executive card). After: 1×.
        $this->assertLessThanOrEqual(
            2,
            $openCasesAggregates,
            "Expected ≤2 executive open_cases aggregates (one build + optional ops reuse), got {$openCasesAggregates}"
            ." (total_queries={$queryCount}, ms=".round($elapsedMs, 1).')',
        );

        $this->assertLessThan(
            280,
            $queryCount,
            "Expected <280 SQL queries on cold warmAll, got {$queryCount} (ms=".round($elapsedMs, 1).')',
        );
    }

    public function test_second_warm_skips_fresh_zones(): void
    {
        $warming = app(PlatformSnapshotWarmingService::class);

        $first = $warming->warmAll();
        $this->assertNotEmpty($first['warmed']);
        $this->assertSame([], $first['failed']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $started = hrtime(true);
        $second = $warming->warmAll();
        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $queryCount = count(DB::getQueryLog());

        $this->assertSame([], $second['failed']);
        $this->assertNotEmpty($second['skipped']);
        $this->assertContains('platform_health', $second['skipped']);
        $this->assertContains('executive_snapshot', $second['skipped']);
        $this->assertContains('email_operations', $second['skipped']);

        // Incremental path should be near-free (lock/freshness checks only).
        $this->assertLessThan(
            40,
            $queryCount,
            "Expected <40 SQL queries when all zones are fresh, got {$queryCount} (ms=".round($elapsedMs, 1).')',
        );
        $this->assertLessThan(
            100,
            $elapsedMs,
            'Expected incremental warmAll under 100ms locally, got '.round($elapsedMs, 1).'ms',
        );
    }
}
