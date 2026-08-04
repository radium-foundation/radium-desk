<?php

namespace Tests\Feature\Platform;

use App\Data\Platform\PlatformAlert;
use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformAlertSeverity;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformOverallHealthStatus;
use App\Models\User;
use App\Services\Administration\AdministrationSystemHealthSummaryService;
use App\Services\Platform\Alerts\PlatformAlertAggregator;
use App\Services\Platform\Alerts\PlatformAlertRegistry;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\PlatformHealthRegistry;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use App\Services\Platform\Warmers\PlatformSnapshotWarmingService;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class DashboardIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'email' => 'intelligence@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_alert_aggregation_orders_by_severity_not_time(): void
    {
        $registry = new PlatformAlertRegistry;
        $registry->register(new class implements \App\Contracts\Platform\PlatformAlertContributor
        {
            public function key(): string
            {
                return 'fixture';
            }

            public function label(): string
            {
                return 'Fixture';
            }

            public function sortOrder(): int
            {
                return 1;
            }

            public function alerts(): array
            {
                return [
                    new PlatformAlert('a', 'fixture', 'info', 'Info', 'info', PlatformAlertSeverity::Information, 'Information', now()->subHour()),
                    new PlatformAlert('b', 'fixture', 'warn', 'Warn', 'warn', PlatformAlertSeverity::Warning, 'Warning', now()),
                    new PlatformAlert('c', 'fixture', 'crit', 'Crit', 'crit', PlatformAlertSeverity::Critical, 'Critical', now()->subDay()),
                ];
            }
        });

        $alerts = (new PlatformAlertAggregator($registry))->collect();

        $this->assertSame(['Crit', 'Warn', 'Info'], array_map(static fn ($a) => $a->title, $alerts));
    }

    public function test_alert_deduplication_groups_related_issues(): void
    {
        $registry = new PlatformAlertRegistry;
        $registry->register(new class implements \App\Contracts\Platform\PlatformAlertContributor
        {
            public function key(): string
            {
                return 'cashfree_fixture';
            }

            public function label(): string
            {
                return 'Cashfree';
            }

            public function sortOrder(): int
            {
                return 1;
            }

            public function alerts(): array
            {
                return [
                    new PlatformAlert('1', 'integration_health', 'cashfree', 'Webhook failure', 'webhooks', PlatformAlertSeverity::Critical, 'Critical'),
                    new PlatformAlert('2', 'integration_health', 'cashfree', 'Integrity issue', 'integrity', PlatformAlertSeverity::Warning, 'Warning'),
                    new PlatformAlert('3', 'integration_health', 'cashfree', 'API unavailable', 'api', PlatformAlertSeverity::Critical, 'Critical'),
                ];
            }
        });

        $alerts = (new PlatformAlertAggregator($registry))->collect();

        $this->assertCount(1, $alerts);
        $this->assertSame(3, $alerts[0]->count);
        $this->assertSame(PlatformAlertSeverity::Critical, $alerts[0]->severity);
        $this->assertSame('3 related issues', $alerts[0]->summary);
        $this->assertCount(3, $alerts[0]->related);
    }

    public function test_health_score_uses_cached_contributions_only(): void
    {
        Cache::put(\App\Services\Platform\Health\PlatformHealthSnapshotService::CACHE_KEY, [
            'status' => PlatformHealthStatus::Healthy->value,
            'status_label' => 'Healthy',
            'components' => [],
            'generated_at' => now()->toIso8601String(),
            'stale' => false,
            'available' => true,
        ], now()->addMinutes(5));

        Cache::put(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY, [
            'status' => PlatformHealthStatus::Healthy->value,
            'status_label' => 'Healthy',
            'generated_at' => now()->toIso8601String(),
        ], now()->addMinutes(5));

        Cache::put(app(PlatformIntegrationHealthOverviewService::class)->itemCacheKey('gmail'), [
            'key' => 'gmail',
            'label' => 'Gmail',
            'status' => IntegrationHealthStatus::Warning->value,
            'status_label' => 'Warning',
            'badge_class' => 'warning',
            'platform_status' => 'warning',
            'platform_status_label' => 'Warning',
            'summary' => 'Gmail lag',
            'detail' => 'Gmail lag',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $health = app(PlatformOverallHealthService::class)->compute();

        $this->assertTrue($health->available);
        $this->assertSame(PlatformOverallHealthStatus::Warning, $health->status);
        $this->assertNotNull($health->scorePercent);
    }

    public function test_snapshot_warmer_warms_priority_one_caches(): void
    {
        $actor = $this->createSuperadmin();

        $result = app(PlatformSnapshotWarmingService::class)->warmAll($actor);

        $this->assertContains('platform_health', $result['warmed']);
        $this->assertContains('executive_snapshot', $result['warmed']);
        $this->assertContains('integration_health', $result['warmed']);
        $this->assertContains('critical_alerts', $result['warmed']);
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('platform_health'));
        $this->assertNotNull(Cache::get(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY));
        $this->assertNotNull(Cache::get(\App\Services\Platform\Health\PlatformHealthSnapshotService::CACHE_KEY));
        $this->assertNotNull(Cache::get(PlatformOverallHealthService::CACHE_KEY));
    }

    public function test_snapshot_warmer_works_without_browser_actor(): void
    {
        Cache::flush();

        $result = app(PlatformSnapshotWarmingService::class)->warmAll(null);

        $this->assertNotEmpty($result['warmed']);
        $this->assertContains('platform_health', $result['warmed']);
        $this->assertContains('executive_snapshot', $result['warmed']);
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('platform_health'));
        $this->assertNotNull(app(PlatformZoneSnapshotStore::class)->get('executive_snapshot'));
    }

    public function test_stale_snapshot_is_preserved_on_mark_stale(): void
    {
        $store = app(PlatformZoneSnapshotStore::class);
        $store->put(new \App\Data\Platform\PlatformZoneSnapshot(
            key: 'platform_health',
            status: PlatformHealthStatus::Healthy,
            statusLabel: 'Healthy',
            updatedAt: now()->subMinutes(5),
            summary: ['state' => 'ready'],
            html: '<div>last-known-good</div>',
            available: true,
            stale: false,
        ));

        $this->assertTrue($store->markStale('platform_health'));

        $snapshot = $store->get('platform_health');
        $this->assertNotNull($snapshot);
        $this->assertTrue($snapshot->stale);
        $this->assertStringContainsString('last-known-good', $snapshot->html);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->status);
    }

    public function test_administration_is_cache_only_and_never_probes(): void
    {
        $probe = Mockery::mock(PlatformHealthRegistry::class);
        $probe->shouldReceive('probeAll')->never();
        $this->app->instance(PlatformHealthRegistry::class, $probe);

        // Rebuild summary service without registry dependency — already removed.
        $summary = app(AdministrationSystemHealthSummaryService::class)->summary();

        $this->assertFalse($summary['platform_available']);
        $this->assertFalse($summary['integration_available']);
        $this->assertTrue($summary['waiting_for_refresh']);
        $this->assertSame('Unavailable', $summary['platform_status_label']);
        $this->assertSame('Waiting for background refresh', $summary['last_updated_label']);

        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Open Platform Dashboard', false);
    }

    public function test_administration_reads_cached_platform_overview_without_probe(): void
    {
        Cache::put(AdministrationSystemHealthSummaryService::PLATFORM_OVERVIEW_CACHE_KEY, [
            'status' => PlatformHealthStatus::Healthy->value,
            'status_label' => 'Healthy',
            'generated_at' => now()->toIso8601String(),
        ], now()->addMinutes(5));

        $probe = Mockery::mock(PlatformHealthRegistry::class);
        $probe->shouldReceive('probeAll')->never();
        $this->app->instance(PlatformHealthRegistry::class, $probe);

        $summary = app(AdministrationSystemHealthSummaryService::class)->summary();

        $this->assertTrue($summary['platform_available']);
        $this->assertSame('Healthy', $summary['platform_status_label']);
        $this->assertFalse($summary['waiting_for_refresh']);
    }

    public function test_critical_alerts_zone_aggregates_from_cached_integrations(): void
    {
        Cache::put(app(PlatformIntegrationHealthOverviewService::class)->itemCacheKey('cashfree'), [
            'key' => 'cashfree',
            'label' => 'Cashfree',
            'status' => IntegrationHealthStatus::Critical->value,
            'status_label' => 'Critical',
            'badge_class' => 'danger',
            'platform_status' => 'critical',
            'platform_status_label' => 'Critical',
            'summary' => 'Webhook failures',
            'detail' => 'Webhook failures',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        Cache::put(app(PlatformIntegrationHealthOverviewService::class)->itemCacheKey('gmail'), [
            'key' => 'gmail',
            'label' => 'Gmail',
            'status' => IntegrationHealthStatus::Warning->value,
            'status_label' => 'Warning',
            'badge_class' => 'warning',
            'platform_status' => 'warning',
            'platform_status_label' => 'Warning',
            'summary' => 'Sync delay',
            'detail' => 'Sync delay',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'critical_alerts']));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('Cashfree', $html);
        $this->assertStringContainsString('Gmail', $html);
        $this->assertGreaterThanOrEqual(2, (int) data_get($response->json('summary'), 'alert_count'));
    }

    public function test_platform_index_shows_overall_health_banner(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('data-platform-overall-health', false)
            ->assertSee('Overall Platform Health', false);
    }
}
