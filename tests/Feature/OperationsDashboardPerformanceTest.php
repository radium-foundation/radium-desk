<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\OperationsDashboardLiveRenderer;
use App\Services\Operations\OperationsDashboardSectionBundles;
use App\Services\Operations\OperationsDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_dashboard_build_profiled_returns_component_timings(): void
    {
        Cache::flush();

        $result = app(OperationsDashboardService::class)->buildProfiled();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('profile', $result);
        $this->assertArrayHasKey('total_ms', $result);
        $this->assertArrayHasKey('support_intelligence', $result['profile']);
        $this->assertArrayHasKey('cashfree_health', $result['profile']);
        $this->assertArrayHasKey('radiumbox_health', $result['profile']);
        $this->assertGreaterThan(0, $result['total_ms']);
    }

    public function test_initial_page_payload_is_smaller_than_full_live_refresh(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $indexResponse = $this->actingAs($admin)->get(route('admin.operations.index'));
        $liveResponse = $this->actingAs($admin)->getJson(route('admin.operations.live'));

        $indexBytes = strlen($indexResponse->getContent());
        $liveBytes = strlen((string) $liveResponse->getContent());

        $this->assertLessThan(
            $liveBytes,
            $indexBytes,
            'Initial SSR payload should be smaller than a full live refresh payload.',
        );

        $this->assertLessThan(
            120000,
            $indexBytes,
            'Initial HTML payload should stay under 120KB for fast first paint.',
        );
    }

    public function test_live_endpoint_supports_partial_group_refresh(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $fullResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live'))
            ->assertOk();

        $partialResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health,ira_compact']))
            ->assertOk()
            ->assertJsonPath('groups', ['critical', 'summary', 'health', 'ira_compact']);

        $fullSections = array_keys($fullResponse->json('html'));
        $partialSections = array_keys($partialResponse->json('html'));

        $this->assertGreaterThan(count($partialSections), count($fullSections));
        $this->assertSame(
            ['critical_alerts', 'overview_cards', 'health_status', 'ira_compact'],
            $partialSections,
        );
    }

    public function test_live_partial_refresh_skips_heavy_tab_shells_when_not_needed(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'performance']))
            ->assertOk()
            ->assertJsonMissingPath('html.critical_alerts')
            ->assertJsonMissingPath('html.ira_compact')
            ->assertJsonMissingPath('html.advisor_insights')
            ->assertJsonStructure([
                'html' => [
                    'performance_tab',
                ],
            ]);
    }

    public function test_live_renderer_exposes_lazy_load_groups_for_command_center(): void
    {
        $lazyGroups = [
            'critical',
            'summary',
            'ira_compact',
            'ira_full',
            'health',
            'health_cashfree',
            'health_radiumbox',
            'health_telegram',
            'today',
            'team',
            'performance',
            'system',
        ];

        foreach ($lazyGroups as $group) {
            $this->assertArrayHasKey($group, OperationsDashboardLiveRenderer::GROUP_SECTIONS);
            $this->assertNotSame([], OperationsDashboardLiveRenderer::GROUP_SECTIONS[$group]);
        }
    }

    public function test_dashboard_snapshot_store_reuses_single_incident_load_per_request(): void
    {
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(DashboardSnapshotStore::class)->get();
        app(DashboardSnapshotStore::class)->get();
        app(DashboardSnapshotStore::class)->get();

        $incidentQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), ' from "incidents"')
                || str_contains(strtolower($query['query']), ' from `incidents`'))
            ->count();

        DB::disableQueryLog();

        $this->assertSame(1, $incidentQueries, 'DashboardSnapshotStore should load incidents only once per request.');
    }

    public function test_partial_dashboard_build_skips_ivr_analytics_bundle(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(['critical', 'summary', 'health', 'ira_compact']);
        $bundles = OperationsDashboardSectionBundles::bundlesForSections($sections);

        $this->assertNotContains(OperationsDashboardSectionBundles::IVR_ANALYTICS, $bundles);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = $service->buildForSections($sections);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $data->ivrAnalytics);
        $this->assertNotEmpty($data->supportIntelligence);
        $this->assertGreaterThan(0, $queries);
    }

    public function test_performance_group_build_excludes_support_intelligence(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(['performance']);
        $data = $service->buildForSections($sections);

        $this->assertNotEmpty($data->ivrAnalytics);
        $this->assertSame([], $data->supportIntelligence);
        $this->assertSame([], $data->teamAvailability);
    }

    public function test_live_partial_refresh_uses_fewer_queries_than_full_refresh(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live'))
            ->assertOk();

        $fullQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health,ira_compact']))
            ->assertOk();

        $partialQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $fullQueryCount,
            $partialQueryCount,
            'Partial live refresh should execute fewer queries than a full refresh.',
        );
    }

    public function test_ira_snapshot_data_is_cached_within_ttl(): void
    {
        Cache::flush();

        $memoryService = app(IraMemoryService::class);

        $first = $memoryService->collectSnapshotData();
        $second = $memoryService->collectSnapshotData();

        $this->assertSame($first->date, $second->date);
        $this->assertSame($first->operations, $second->operations);

        $cached = Cache::get('ira:operations:snapshot-data:'.now()->toDateString());
        $this->assertIsArray($cached);
        $this->assertArrayHasKey('date', $cached);
        $this->assertArrayHasKey('operations', $cached);
    }

    public function test_audit_log_queries_are_bounded(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $snapshot = $service->snapshot();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $logs = $snapshot->todayNotificationAuditLogs();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $selectQuery = collect($queries)
            ->first(fn (array $query): bool => str_contains(strtolower($query['query']), 'audit_logs')
                && str_contains(strtolower($query['query']), 'select'));

        $this->assertNotNull($selectQuery);
        $this->assertLessThanOrEqual(
            (int) config('operations.dashboard.audit_log_limit', 2000),
            $logs->count(),
        );
    }
}
