<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\DashboardService;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraMemoryService;
use App\Services\Operations\OperationsDashboardLiveRenderer;
use App\Services\Operations\OperationsDashboardSectionBundles;
use App\Services\Operations\OperationsDashboardService;
use App\Services\Operations\OperationsQueueClassifier;
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

    public function test_initial_page_payload_stays_within_first_paint_budget(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $indexResponse = $this->actingAs($admin)->get(route('admin.operations.index'));
        $indexBytes = strlen($indexResponse->getContent());

        $firstPaintSections = OperationsDashboardLiveRenderer::resolveSections(
            OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS,
        );
        $normalized = $firstPaintSections;
        sort($normalized);
        $sectionCacheKey = 'operations:dashboard:sections:'.hash('xxh128', implode(',', $normalized));

        $this->assertTrue(Cache::has($sectionCacheKey), 'SSR should warm first-paint section cache.');
        $this->assertFalse(Cache::has('operations:dashboard:latest:v2'), 'SSR should not warm the full dashboard cache.');
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
            ->getJson(route('admin.operations.live', [
                'groups' => implode(',', OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS),
            ]))
            ->assertOk()
            ->assertJsonPath('groups', OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS);

        $fullSections = array_keys($fullResponse->json('html'));
        $partialSections = array_keys($partialResponse->json('html'));

        $this->assertGreaterThan(count($partialSections), count($fullSections));
        $this->assertSame(
            ['critical_alerts', 'overview_cards', 'queue_summary', 'active_operators'],
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
            'queue',
            'operators',
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

    public function test_filter_counts_classifies_each_active_incident_only_once(): void
    {
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();
        app(OperationsQueueClassifier::class)->forgetClassifications();

        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $activeCount = 12;

        for ($index = 1; $index <= $activeCount; $index++) {
            $order = Order::query()->create([
                'order_id' => 'RB-PERF-'.$index,
                'serial_number' => null,
                'product_name' => 'MFS110',
                'device_model' => 'MFS110',
                'status' => 'active',
                'created_by' => $creator->id,
            ]);

            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => app(IncidentReferenceService::class)->generate(),
                'category' => 'General',
                'source' => IncidentSource::Call,
                'title' => 'Perf case '.$index,
                'description' => 'Perf case '.$index,
                'status' => IncidentStatus::Open,
                'created_by' => $creator->id,
                'updated_by' => $creator->id,
            ]);
        }

        $classifier = app(OperationsQueueClassifier::class);
        $classifier->forgetClassifications();

        $counts = app(DashboardService::class)->serviceCaseFilterCounts();

        $this->assertSame(
            $activeCount,
            $classifier->classificationComputeCount(),
            'filterCounts/queueCounts should classify each active incident only once per request.',
        );

        foreach (OperationQueue::cases() as $queue) {
            $this->assertArrayHasKey($queue->value, $counts);
            $this->assertIsInt($counts[$queue->value]);
        }

        // Second pass must reuse memoized classifications and queue buckets.
        $before = $classifier->classificationComputeCount();
        app(DashboardService::class)->serviceCaseFilterCounts();
        $this->assertSame($before, $classifier->classificationComputeCount());
    }

    public function test_deferred_command_center_build_skips_ivr_analytics_bundle(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(
            OperationsDashboardLiveRenderer::DEFERRED_COMMAND_CENTER_GROUPS,
        );
        $bundles = OperationsDashboardSectionBundles::bundlesForSections($sections);

        $this->assertNotContains(OperationsDashboardSectionBundles::IVR_ANALYTICS, $bundles);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $data = $service->buildForSections($sections);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([], $data->ivrAnalytics);
        $this->assertSame([], $data->supportIntelligence);
        $this->assertSame([], $data->teamAvailability['on_duty'] ?? []);
        // health_status + ira_compact declare no data bundles — zero SQL is correct.
        $this->assertSame(0, $queries);
    }

    public function test_full_refresh_sections_map_to_requested_bundles_only(): void
    {
        $bundles = OperationsDashboardSectionBundles::bundlesForSections(
            OperationsDashboardLiveRenderer::ALL_SECTIONS,
        );

        $this->assertContains(OperationsDashboardSectionBundles::SUPPORT_INTELLIGENCE, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::IVR_ANALYTICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::QUEUE_METRICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::TEAM_AVAILABILITY, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::TEAM_TELEGRAM_STATUS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::NOTIFICATION_METRICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::AUTOMATION_METRICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::CASHFREE_DEVICE_ENRICHMENT, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::MISSING_SERIAL_AUTOMATION, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::RECENT_NOTIFICATION_FAILURES, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::RECENT_AUTOMATION_ACTIVITY, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::RECENT_IRA_MESSAGES, $bundles);

        // Static health_status shell must not pull these heavy / unused bundles.
        $this->assertNotContains(OperationsDashboardSectionBundles::CASHFREE_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::INTEGRATION_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::RADIUMBOX_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::GMAIL_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::SYSTEM_HEALTH, $bundles);

        $this->assertNotSame(
            OperationsDashboardSectionBundles::allBundles(),
            $bundles,
            'Full refresh must not collapse to allBundles().',
        );
    }

    public function test_full_live_refresh_skips_unrequested_health_bundles(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::ALL_SECTIONS;

        $legacyQueryCount = $this->queryCountFor(fn () => $service->build());
        Cache::flush();
        $requestedQueryCount = $this->queryCountFor(fn () => $service->dashboardDataForSections($sections, useCache: false));

        $data = $service->dashboardDataForSections($sections, useCache: false);

        $this->assertSame([], $data->cashfreeHealth);
        $this->assertSame([], $data->integrationHealth);
        $this->assertSame([], $data->gmailHealth);
        $this->assertSame([], $data->systemHealth);
        $this->assertNotEmpty($data->supportIntelligence);
        $this->assertArrayHasKey('pending', $data->queueMetrics);
        $this->assertLessThan(
            $legacyQueryCount,
            $requestedQueryCount,
            'Requested-bundle full refresh should execute fewer queries than allBundles()/build().',
        );
    }

    public function test_team_performance_quality_scan_does_not_n_plus_one_waiting_or_orders(): void
    {
        Cache::flush();
        app(DashboardSnapshotStore::class)->forget();

        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agents = [];
        for ($agentIndex = 1; $agentIndex <= 3; $agentIndex++) {
            $agent = User::factory()->create([
                'name' => 'Perf Agent '.$agentIndex,
                'is_active' => true,
            ]);
            $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
            $agents[] = $agent;

            for ($caseIndex = 1; $caseIndex <= 8; $caseIndex++) {
                $order = Order::query()->create([
                    'order_id' => 'RB-N1-'.$agentIndex.'-'.$caseIndex,
                    'serial_number' => null,
                    'product_name' => 'MFS110',
                    'device_model' => 'MFS110',
                    'status' => 'active',
                    'created_by' => $creator->id,
                ]);

                $incident = Incident::query()->create([
                    'order_id' => $order->id,
                    'reference_no' => app(IncidentReferenceService::class)->generate(),
                    'category' => 'General',
                    'source' => IncidentSource::Call,
                    'title' => 'N+1 case '.$agentIndex.'-'.$caseIndex,
                    'description' => 'N+1 case '.$agentIndex.'-'.$caseIndex,
                    'status' => IncidentStatus::Open,
                    'created_by' => $creator->id,
                    'updated_by' => $creator->id,
                    'assigned_to_user_id' => $agent->id,
                ]);

                \App\Models\IncidentWaitingState::query()->create([
                    'incident_id' => $incident->id,
                    'waiting_reason' => \App\Enums\WaitingReason::SerialNumber,
                    'started_at' => now()->subHour(),
                    'sla_paused' => true,
                    'reminder_policy_key' => 'request_serial',
                    'created_by' => $creator->id,
                    'updated_by' => $creator->id,
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(\App\Services\Operations\TeamPerformanceMetricsService::class)->teamMetrics();

        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $waitingById = $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'incident_waiting_states')
            && str_contains(strtolower($query['query']), 'incident_id` = ?'))->count();
        $ordersById = $queries->filter(fn (array $query): bool => (str_contains(strtolower($query['query']), 'from `orders`')
                || str_contains(strtolower($query['query']), 'from "orders"'))
            && str_contains(strtolower($query['query']), 'id` = ?'))->count();

        $this->assertSame(0, $waitingById, 'Quality scan must not lazy-load activeWaitingState per incident.');
        $this->assertSame(0, $ordersById, 'Quality scan must not lazy-load order per incident.');
    }

    public function test_first_paint_build_includes_support_team_and_queue_bundles(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(
            OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS,
        );
        $bundles = OperationsDashboardSectionBundles::bundlesForSections($sections);

        $this->assertContains(OperationsDashboardSectionBundles::SUPPORT_INTELLIGENCE, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::TEAM_AVAILABILITY, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::QUEUE_METRICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::IVR_ANALYTICS, $bundles);

        $data = $service->buildForSections($sections);

        $this->assertIsArray($data->supportIntelligence);
        $this->assertIsArray($data->queueMetrics);
        $this->assertArrayHasKey('pending', $data->queueMetrics);
        $this->assertIsArray($data->teamAvailability);
        $this->assertIsArray($data->ivrAnalytics);
    }

    public function test_performance_group_build_excludes_support_intelligence(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(['performance']);
        $data = $service->buildForSections($sections);

        $this->assertNotEmpty($data->ivrAnalytics);
        $this->assertSame([], $data->supportIntelligence);
        $this->assertSame([], $data->teamAvailability['on_duty'] ?? []);
        $this->assertSame([], $data->teamAvailability['unavailable'] ?? []);
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
            ->getJson(route('admin.operations.live', [
                'groups' => implode(',', OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS),
            ]))
            ->assertOk();

        $partialQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            $fullQueryCount,
            $partialQueryCount,
            'Partial live refresh should execute fewer queries than a full refresh.',
        );
    }

    public function test_partial_dashboard_build_uses_section_cache_within_ttl(): void
    {
        Cache::flush();

        $service = app(OperationsDashboardService::class);
        $sections = OperationsDashboardLiveRenderer::resolveSections(['critical', 'summary', 'health', 'ira_compact']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $service->dashboardDataForSections($sections);

        $coldQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $service->dashboardDataForSections($sections);

        $warmQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $normalized = $sections;
        sort($normalized);
        $cacheKey = 'operations:dashboard:sections:'.hash('xxh128', implode(',', $normalized));

        $this->assertTrue(Cache::has($cacheKey));
        $this->assertGreaterThan(0, $coldQueryCount);
        $this->assertSame(0, $warmQueryCount, 'Warm partial dashboard build should reuse section cache.');
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

    private function queryCountFor(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
