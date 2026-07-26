<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Services\Operations\OperationsDashboardLiveRenderer;
use App\Services\Operations\OperationsDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfileOperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_profile_operations_dashboard_performance(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $firstPaintGroups = OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS;
        $legacyGroups = ['critical', 'summary', 'health', 'ira_compact'];
        $deferredGroups = OperationsDashboardLiveRenderer::DEFERRED_COMMAND_CENTER_GROUPS;
        $firstPaintSections = OperationsDashboardLiveRenderer::resolveSections($firstPaintGroups);

        $fullProfiled = app(OperationsDashboardService::class)->buildProfiled();
        $firstPaintProfiled = $this->profileBuildWithTimings($firstPaintSections);
        $legacyProfiled = $this->profileBuildWithTimings(
            OperationsDashboardLiveRenderer::resolveSections($legacyGroups),
        );

        $report = [
            'first_paint_groups' => $firstPaintGroups,
            'deferred_groups' => $deferredGroups,
            'legacy_first_paint_groups' => $legacyGroups,
            'full_build_profile' => [
                'total_ms' => $fullProfiled['total_ms'],
                'bundle_timings_ms' => $fullProfiled['profile'],
            ],
            'legacy_first_paint_build' => $legacyProfiled,
            'first_paint_build' => $firstPaintProfiled,
            'full_http_cold' => $this->profileHttpRequest($admin, true),
            'full_http_warm' => $this->profileHttpRequest($admin, false),
            'live_first_paint_cold' => $this->profileLiveEndpoint($admin, implode(',', $firstPaintGroups), true),
            'live_first_paint_warm' => $this->profileLiveEndpoint($admin, implode(',', $firstPaintGroups), false),
            'live_deferred_cold' => $this->profileLiveEndpoint($admin, implode(',', $deferredGroups), true),
            'live_full_cold' => $this->profileLiveEndpoint($admin, null, true),
            'blade_render_first_paint_ms' => $this->profileBladeRender($firstPaintSections),
        ];

        file_put_contents(
            storage_path('app/operations-dashboard-profile.json'),
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->assertTrue(true);
    }

    /**
     * @param  list<string>  $sections
     * @return array<string, mixed>
     */
    private function profileBuildWithTimings(array $sections): array
    {
        Cache::flush();

        $profiler = new \App\Services\Operations\OperationsDashboardBuildProfiler;
        $queryCount = 0;
        $slowest = ['sql' => '', 'ms' => 0.0];

        DB::listen(function ($query) use (&$queryCount, &$slowest): void {
            $queryCount++;
            $ms = (float) $query->time;

            if ($ms > $slowest['ms']) {
                $slowest = ['sql' => $query->sql, 'ms' => $ms];
            }
        });

        $start = hrtime(true);
        app(OperationsDashboardService::class)->buildForSections($sections, $profiler);
        $ms = (hrtime(true) - $start) / 1e6;

        return [
            'sections' => $sections,
            'build_ms' => round($ms, 2),
            'profile_ms' => $profiler->totalMs(),
            'bundle_timings_ms' => $profiler->timings(),
            'queries' => $queryCount,
            'slowest_query_ms' => round($slowest['ms'], 3),
        ];
    }

    /**
     * @param  list<string>  $sections
     */
    private function profileBladeRender(array $sections): float
    {
        Cache::flush();

        $dashboard = app(OperationsDashboardService::class)->buildForSections($sections);
        $renderer = app(OperationsDashboardLiveRenderer::class);

        $start = hrtime(true);
        $renderer->renderSections($sections, $dashboard, null, null, 'rule_based', []);
        $ms = (hrtime(true) - $start) / 1e6;

        return round($ms, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function profileHttpRequest(User $admin, bool $cold): array
    {
        if ($cold) {
            Cache::flush();
        }

        $queries = [];
        $slowest = ['sql' => '', 'ms' => 0.0];

        DB::listen(function ($query) use (&$queries, &$slowest): void {
            $queries[] = $query->sql;
            $ms = (float) $query->time;

            if ($ms > $slowest['ms']) {
                $slowest = ['sql' => $query->sql, 'ms' => $ms];
            }
        });

        $start = hrtime(true);
        $response = $this->actingAs($admin)->get(route('admin.operations.index'));
        $ms = (hrtime(true) - $start) / 1e6;

        return [
            'status' => $response->status(),
            'request_ms' => round($ms, 2),
            'html_bytes' => strlen($response->getContent()),
            'queries' => count($queries),
            'slowest_query_ms' => round($slowest['ms'], 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileLiveEndpoint(User $admin, ?string $groups, bool $cold): array
    {
        if ($cold) {
            Cache::flush();
        }

        $queries = [];
        $params = $groups !== null ? ['groups' => $groups] : [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $start = hrtime(true);
        $response = $this->actingAs($admin)->getJson(route('admin.operations.live', $params));
        $ms = (hrtime(true) - $start) / 1e6;

        return [
            'status' => $response->status(),
            'request_ms' => round($ms, 2),
            'queries' => count($queries),
            'json_bytes' => strlen($response->getContent()),
        ];
    }
}
