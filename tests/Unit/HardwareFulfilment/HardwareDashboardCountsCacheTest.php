<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Services\HardwareFulfilment\HardwareDashboardCountsCache;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HardwareDashboardCountsCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_range_identity_buckets_to_within_ttl_window(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);

        $cache = app(HardwareDashboardCountsCache::class);
        $from = Carbon::parse('2026-11-11 10:00:00', 'Asia/Kolkata');
        $toA = Carbon::parse('2026-11-11 10:00:05', 'Asia/Kolkata');
        $toB = Carbon::parse('2026-11-11 10:00:25', 'Asia/Kolkata');
        $toNextBucket = Carbon::parse('2026-11-11 10:00:35', 'Asia/Kolkata');

        $this->assertSame(
            $cache->rangeCacheIdentity($from, $toA),
            $cache->rangeCacheIdentity($from, $toB),
        );
        $this->assertNotSame(
            $cache->rangeCacheIdentity($from, $toB),
            $cache->rangeCacheIdentity($from, $toNextBucket),
        );
    }

    public function test_different_search_terms_produce_different_identities(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);

        $cache = app(HardwareDashboardCountsCache::class);
        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::parse('2026-11-11 16:00:10', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $identityA = $cache->rangeCacheIdentity($from, $to).'|ALPHA';
        $identityB = $cache->rangeCacheIdentity($from, $to).'|BETA';

        $this->assertNotSame($identityA, $identityB);
    }

    public function test_second_call_in_same_bucket_reuses_cache_without_duplicate_sql(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);
        config(['cache.default' => 'array']);
        Cache::flush();

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $toFirst = Carbon::parse('2026-11-11 16:00:05', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $toSecond = Carbon::parse('2026-11-11 16:00:20', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);

        $cache = app(HardwareDashboardCountsCache::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $first = $cache->filterCounts($from, $toFirst, '');
        $coldQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $second = $cache->filterCounts($from, $toSecond, '');
        $warmQueries = count(DB::getQueryLog());

        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $coldQueries);
        $this->assertSame(0, $warmQueries);
    }

    public function test_request_memoization_avoids_duplicate_sql_in_one_request(): void
    {
        config(['dashboard.hardware_counts_ttl_seconds' => 30]);
        config(['cache.default' => 'array']);
        Cache::flush();

        $from = HardwareFulfilmentEligibility::cutoffInstant();
        $to = Carbon::parse('2026-11-11 16:05:00', HardwareFulfilmentEligibility::CUTOFF_TIMEZONE);
        $cache = app(HardwareDashboardCountsCache::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cache->filterCounts($from, $to, '');
        $afterFirst = count(DB::getQueryLog());

        DB::flushQueryLog();
        $cache->filterCounts($from, $to, '');
        $afterSecond = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame(0, $afterSecond);
    }
}
