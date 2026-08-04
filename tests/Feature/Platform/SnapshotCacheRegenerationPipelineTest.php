<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformHealthStatus;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\PlatformDashboardService;
use App\Services\Platform\PlatformHealthCache;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SnapshotCacheRegenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        PlatformHealthCache::clearDurableForTests();
        Carbon::setTestNow(Carbon::parse('2026-08-04 22:15:00', 'Asia/Kolkata'));
        config(['infrastructure.queue_worker_mode' => 'dedicated_cron']);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => '1'],
        );
        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PlatformHealthCache::clearDurableForTests();

        parent::tearDown();
    }

    public function test_optimize_clear_style_cache_flush_does_not_persist_false_critical_from_missing_cache_heartbeat(): void
    {
        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinute());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now()->subMinute());

        $viewer = $this->superadmin();
        app(PlatformDashboardService::class)->refreshZone($viewer, 'platform_health');

        $first = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($first);
        $this->assertSame(PlatformHealthStatus::Healthy, $first->component('scheduler')?->status);
        $this->assertSame(PlatformHealthStatus::Healthy, $first->component('presence')?->status);
        $this->assertNotSame(PlatformHealthStatus::Critical, $first->status);

        // Simulate optimize:clear → cache:clear (durable heartbeats remain).
        Cache::flush();

        $this->assertNull(Cache::get(PlatformHealthSnapshotService::CACHE_KEY));
        $this->assertNotNull(PlatformHealthCache::schedulerLastRunAt());

        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk()
            ->assertJsonPath('key', 'platform_health');

        $snapshot = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($snapshot);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->component('scheduler')?->status);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->component('presence')?->status);
        $this->assertNotSame(PlatformHealthStatus::Critical, $snapshot->status);
    }

    public function test_critical_alerts_refresh_does_not_persist_cold_pending_snapshot(): void
    {
        $viewer = $this->superadmin();
        $store = app(PlatformZoneSnapshotStore::class);

        $snapshot = app(PlatformDashboardService::class)->refreshZone($viewer, 'critical_alerts');

        $this->assertFalse($snapshot->available);
        $this->assertSame('Pending', $snapshot->statusLabel);
        $this->assertNull($store->get('critical_alerts'));
    }

    public function test_http_zone_refresh_invalidates_critical_alerts_after_platform_health_probe(): void
    {
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());

        $viewer = $this->superadmin();
        $store = app(PlatformZoneSnapshotStore::class);

        $store->put(new \App\Data\Platform\PlatformZoneSnapshot(
            key: 'critical_alerts',
            status: PlatformHealthStatus::Critical,
            statusLabel: 'Critical',
            updatedAt: now()->subMinutes(5),
            summary: ['state' => 'alerts'],
            html: '<div>poison</div>',
            available: true,
        ));

        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk();

        $this->assertNull($store->get('critical_alerts'));
        $this->assertNotNull(Cache::get(PlatformCachePolicy::KEY_PLATFORM_HEALTH_SNAPSHOT));
    }

    public function test_second_refresh_after_flush_keeps_non_critical_when_durable_heartbeat_exists(): void
    {
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());
        $viewer = $this->superadmin();

        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk();

        $this->assertNotSame(
            PlatformHealthStatus::Critical,
            app(PlatformHealthSnapshotService::class)->current()?->status,
        );

        Cache::flush();

        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk();

        $snapshot = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($snapshot);
        $this->assertSame(PlatformHealthStatus::Healthy, $snapshot->component('scheduler')?->status);
        $this->assertNotSame(PlatformHealthStatus::Critical, $snapshot->status);
    }

    private function superadmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }
}
