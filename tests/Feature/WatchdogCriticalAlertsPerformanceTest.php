<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchdogCriticalAlertsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'ira.watchdog.enabled' => true,
            'ira.watchdog.automation_failure_threshold' => 2,
            'app.url' => 'http://localhost',
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_watchdog_command_stays_under_budget_without_outbound_http(): void
    {
        // If site health still used Http::timeout(10)->retry(2), this delay would
        // push wall time well over the 3s budget (production was ~21s).
        Http::fake([
            '*' => function () {
                usleep(10_000_000);

                return Http::response('OK', 200);
            },
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $started = hrtime(true);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();

        $elapsedMs = (hrtime(true) - $started) / 1e6;
        $queryCount = count(DB::getQueryLog());

        $this->assertLessThan(
            3000,
            $elapsedMs,
            "Expected watchdog:send-critical-alerts <3000ms, got {$elapsedMs}ms with {$queryCount} queries",
        );

        Http::assertNothingSent();
    }
}
