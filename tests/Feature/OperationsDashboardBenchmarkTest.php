<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsDashboardBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_records_live_endpoint_benchmark_metrics(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $full = $this->measureLiveRequest($admin);
        $partial = $this->measureLiveRequest($admin, 'critical,summary,health,ira_compact');
        $performance = $this->measureLiveRequest($admin, 'performance');

        fwrite(STDERR, json_encode([
            'full_live' => $full,
            'partial_live' => $partial,
            'performance_group' => $performance,
        ], JSON_PRETTY_PRINT).PHP_EOL);

        $this->assertLessThan($full['queries'], $partial['queries']);
        $this->assertLessThan($full['queries'], $performance['queries']);
        $this->assertLessThan($full['ms'], $partial['ms']);
    }

    /**
     * @return array{ms: float, queries: int}
     */
    private function measureLiveRequest(User $admin, ?string $groups = null): array
    {
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = microtime(true);

        $url = route('admin.operations.live', $groups !== null ? ['groups' => $groups] : []);
        $this->actingAs($admin)->getJson($url)->assertOk();

        $ms = round((microtime(true) - $start) * 1000, 1);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['ms' => $ms, 'queries' => $queries];
    }
}
