<?php

namespace Tests\Feature\Platform;

use App\Data\Platform\PlatformHealthComponent;
use App\Data\Platform\PlatformHealthSnapshot;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformAlertSeverity;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use App\Services\Platform\Alerts\Contributors\PlatformHealthAlertContributor;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\PlatformDashboardService;
use App\Services\Platform\PlatformHealthCache;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformHealthSnapshotUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-04 21:30:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_probe_writes_shared_snapshot_and_overview(): void
    {
        $snapshot = app(PlatformHealthSnapshotService::class)->probe();

        $this->assertTrue($snapshot->available);
        $this->assertNotNull(Cache::get(PlatformHealthSnapshotService::CACHE_KEY));
        $this->assertNotNull(Cache::get(PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW));
        $this->assertSame(
            $snapshot->status->value,
            Cache::get(PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW)['status'],
        );
    }

    public function test_critical_alerts_platform_health_matches_shared_snapshot_after_refresh(): void
    {
        $store = app(PlatformZoneSnapshotStore::class);

        // Stale Critical Alerts bake claiming Platform Health is Critical.
        $store->put(new PlatformZoneSnapshot(
            key: 'critical_alerts',
            status: PlatformHealthStatus::Critical,
            statusLabel: 'Critical',
            updatedAt: now()->subMinutes(10),
            summary: [
                'state' => 'alerts',
                'alert_count' => 1,
                'alerts' => [[
                    'id' => 'platform_health:status',
                    'title' => 'Platform Health',
                    'severity' => 'critical',
                    'status' => 'Critical',
                ]],
            ],
            html: '<div>stale Platform Health Critical</div>',
            available: true,
        ));

        $this->assertNotNull($store->get('critical_alerts'));

        // Fresh healthy shared snapshot — invalidates critical_alerts bake.
        app(PlatformHealthSnapshotService::class)->store(new PlatformHealthSnapshot(
            status: PlatformHealthStatus::Healthy,
            statusLabel: 'Healthy',
            components: [
                new PlatformHealthComponent(
                    key: 'scheduler',
                    label: 'Scheduler',
                    status: PlatformHealthStatus::Healthy,
                    detail: 'ok',
                    checkedAt: now(),
                ),
            ],
            generatedAt: now(),
        ));

        $this->assertNull($store->get('critical_alerts'));
        $this->assertSame([], app(PlatformHealthAlertContributor::class)->alerts());
    }

    public function test_refreshing_platform_health_zone_invalidates_critical_alerts_snapshot(): void
    {
        $viewer = $this->superadmin();
        $store = app(PlatformZoneSnapshotStore::class);

        $store->put(new PlatformZoneSnapshot(
            key: 'critical_alerts',
            status: PlatformHealthStatus::Critical,
            statusLabel: 'Critical',
            updatedAt: now()->subMinutes(5),
            summary: ['state' => 'alerts'],
            html: '<div>old</div>',
            available: true,
        ));

        app(PlatformDashboardService::class)->refreshZone($viewer, 'platform_health');

        $this->assertNull($store->get('critical_alerts'));
        $shared = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($shared);
        $zone = $store->get('platform_health');
        $this->assertNotNull($zone);
        $this->assertSame($shared->status, $zone->status);
    }

    public function test_alert_contributor_reads_shared_snapshot_not_zone_html(): void
    {
        app(PlatformHealthSnapshotService::class)->store(new PlatformHealthSnapshot(
            status: PlatformHealthStatus::Warning,
            statusLabel: 'Warning',
            components: [
                new PlatformHealthComponent(
                    key: 'queue',
                    label: 'Queue',
                    status: PlatformHealthStatus::Warning,
                    detail: 'Queue backlog',
                    checkedAt: now(),
                ),
            ],
            generatedAt: now(),
        ));

        $alerts = app(PlatformHealthAlertContributor::class)->alerts();

        $this->assertCount(1, $alerts);
        $this->assertSame('Platform Health', $alerts[0]->title);
        $this->assertSame(PlatformAlertSeverity::Warning, $alerts[0]->severity);
        $this->assertSame('Warning', $alerts[0]->status);
    }

    public function test_executive_snapshot_critical_can_coexist_with_healthy_platform_health(): void
    {
        app(PlatformHealthSnapshotService::class)->store(new PlatformHealthSnapshot(
            status: PlatformHealthStatus::Healthy,
            statusLabel: 'Healthy',
            components: [],
            generatedAt: now(),
        ));

        app(PlatformZoneSnapshotStore::class)->put(new PlatformZoneSnapshot(
            key: 'executive_snapshot',
            status: PlatformHealthStatus::Critical,
            statusLabel: 'Critical',
            updatedAt: now(),
            summary: ['state' => 'ready', 'card_count' => 8],
            html: '<div>kpi critical</div>',
            available: true,
        ));

        $platform = app(PlatformHealthSnapshotService::class)->current();
        $executive = app(PlatformZoneSnapshotStore::class)->get('executive_snapshot');

        $this->assertSame(PlatformHealthStatus::Healthy, $platform?->status);
        $this->assertSame(PlatformHealthStatus::Critical, $executive?->status);
        $this->assertSame([], app(PlatformHealthAlertContributor::class)->alerts());
    }

    private function superadmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }
}
