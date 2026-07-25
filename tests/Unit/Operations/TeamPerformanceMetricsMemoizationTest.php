<?php

namespace Tests\Unit\Operations;

use App\Enums\PerformancePeriod;
use App\Models\User;
use App\Services\Operations\TeamPerformanceMetricsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamPerformanceMetricsMemoizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_team_metrics_are_memoized_within_the_same_request(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $service = app(TeamPerformanceMetricsService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $first = $service->teamMetrics(PerformancePeriod::Today);
        $coldQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $second = $service->teamMetrics(PerformancePeriod::Today);
        $warmQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, $coldQueries);
        $this->assertSame(0, $warmQueries);
    }
}
