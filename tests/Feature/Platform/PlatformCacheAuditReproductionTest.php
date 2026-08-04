<?php

namespace Tests\Feature\Platform;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Platform\Health\PlatformHealthSnapshotService;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\PlatformHealthCache;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Services\SystemSettingsService;
use App\Support\Platform\PlatformCacheAudit;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Temporary investigation harness — reproduces clear → load → background refresh → reload
 * with PLATFORM_CACHE_AUDIT enabled. Does not assert a fix; captures writer facts.
 */
class PlatformCacheAuditReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-04 22:45:00', 'Asia/Kolkata'));
        config([
            'platform.cache_audit' => true,
            'infrastructure.queue_worker_mode' => 'disabled',
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => '0'],
        );
        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');

        PlatformCacheAudit::clearLog();
        PlatformCacheAudit::resetRequestId();
        PlatformHealthCache::clearDurableForTests();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PlatformHealthCache::clearDurableForTests();
        config(['platform.cache_audit' => false]);

        parent::tearDown();
    }

    public function test_reproduce_clear_load_refresh_reload_and_capture_writers(): void
    {
        // Pre-clear healthy heartbeats (cron had been running).
        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinute());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now()->subMinute());

        // Seed a "good" shared snapshot that optimize:clear will wipe (simulates pre-clear truth).
        $viewer = $this->superadmin();
        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']))
            ->assertOk();

        $preClear = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($preClear);

        PlatformCacheAudit::clearLog();
        PlatformCacheAudit::resetRequestId();

        // 1) optimize:clear equivalent
        Cache::flush();
        $this->assertNull(Cache::get(PlatformHealthSnapshotService::CACHE_KEY));

        // 2) Platform page first paint
        $this->actingAs($viewer)
            ->get(route('admin.platform.index'))
            ->assertOk();

        $afterIndex1 = app(PlatformHealthSnapshotService::class)->current();
        $zoneAfterIndex1 = app(PlatformZoneSnapshotStore::class)->get('platform_health');

        // 3) Background refresh (JS priority auto-refresh order)
        foreach (['executive_snapshot', 'platform_health', 'integration_health'] as $zone) {
            $this->actingAs($viewer)
                ->getJson(route('admin.platform.zones.show', ['zone' => $zone]))
                ->assertOk();
        }
        $this->actingAs($viewer)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'critical_alerts']))
            ->assertOk();

        $afterRefresh = app(PlatformHealthSnapshotService::class)->current();
        $zoneAfterRefresh = app(PlatformZoneSnapshotStore::class)->get('platform_health');

        // 4) Browser refresh
        $this->actingAs($viewer)
            ->get(route('admin.platform.index'))
            ->assertOk();

        $afterIndex2 = app(PlatformHealthSnapshotService::class)->current();
        $zoneAfterIndex2 = app(PlatformZoneSnapshotStore::class)->get('platform_health');

        $entries = PlatformCacheAudit::entries();
        $writes = array_values(array_filter(
            $entries,
            static fn (array $e): bool => ($e['direction'] ?? '') === 'write' && ($e['op'] ?? '') === 'put',
        ));
        $snapshotWrites = array_values(array_filter(
            $writes,
            static fn (array $e): bool => ($e['cache_key'] ?? '') === PlatformCachePolicy::KEY_PLATFORM_HEALTH_SNAPSHOT,
        ));
        $zonePhWrites = array_values(array_filter(
            $writes,
            static fn (array $e): bool => ($e['cache_key'] ?? '') === PlatformCachePolicy::zoneSnapshotKey('platform_health'),
        ));

        $facts = [
            'pre_clear_status' => $preClear->status->value,
            'pre_clear_components' => array_map(
                static fn ($c) => [$c->key => $c->status->value],
                $preClear->components,
            ),
            'after_index1_shared' => $afterIndex1?->status->value,
            'after_index1_zone' => $zoneAfterIndex1?->status->value,
            'after_index1_zone_available' => $zoneAfterIndex1?->available,
            'after_refresh_shared' => $afterRefresh?->status->value,
            'after_refresh_components' => $afterRefresh === null ? null : array_map(
                static fn ($c) => [$c->key => $c->status->value],
                $afterRefresh->components,
            ),
            'after_refresh_zone' => $zoneAfterRefresh?->status->value,
            'after_index2_shared' => $afterIndex2?->status->value,
            'after_index2_zone' => $zoneAfterIndex2?->status->value,
            'snapshot_write_count' => count($snapshotWrites),
            'snapshot_writes' => array_map(static fn (array $e): array => [
                'ts' => $e['ts'] ?? null,
                'request_id' => $e['request_id'] ?? null,
                'uri' => $e['uri'] ?? null,
                'route' => $e['route'] ?? null,
                'controller' => $e['controller'] ?? null,
                'service' => $e['service'] ?? null,
                'method' => $e['method'] ?? null,
                'old_status' => $e['old_status'] ?? null,
                'new_status' => $e['new_status'] ?? null,
                'new_summary' => $e['new_summary'] ?? null,
                'stack0' => ($e['stack'][0] ?? null),
                'stack1' => ($e['stack'][1] ?? null),
                'stack2' => ($e['stack'][2] ?? null),
                'stack3' => ($e['stack'][3] ?? null),
            ], $snapshotWrites),
            'zone_platform_health_writes' => array_map(static fn (array $e): array => [
                'ts' => $e['ts'] ?? null,
                'uri' => $e['uri'] ?? null,
                'service' => $e['service'] ?? null,
                'method' => $e['method'] ?? null,
                'old_status' => $e['old_status'] ?? null,
                'new_status' => $e['new_status'] ?? null,
                'stack0' => ($e['stack'][0] ?? null),
                'stack1' => ($e['stack'][1] ?? null),
            ], $zonePhWrites),
            'unique_write_services' => array_values(array_unique(array_map(
                static fn (array $e): string => (string) ($e['service'] ?? '').'::'.(string) ($e['method'] ?? ''),
                $writes,
            ))),
            'entry_count' => count($entries),
        ];

        file_put_contents(
            storage_path('logs/platform-cache-audit-facts.json'),
            json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        // Investigation assertions — prove writers were captured (not a product fix).
        $this->assertGreaterThan(0, count($snapshotWrites), 'Expected at least one platform:health:snapshot write');
        $this->assertSame(
            $afterRefresh?->status->value,
            $afterIndex2?->status->value,
            'Second index must read the same shared snapshot the refresh wrote',
        );
        $this->assertNotNull($afterRefresh);
    }

    public function test_reproduce_with_active_queue_and_automation(): void
    {
        config(['infrastructure.queue_worker_mode' => 'dedicated_cron']);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'automation.scheduler.enabled'],
            ['value' => '1'],
        );
        app(SystemSettingsService::class)->forget('automation.scheduler.enabled');

        PlatformHealthCache::recordSchedulerHeartbeat(now()->subMinute());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now()->subMinute());
        PlatformCacheAudit::clearLog();

        $viewer = $this->superadmin();
        Cache::flush();

        $this->actingAs($viewer)->get(route('admin.platform.index'))->assertOk();
        foreach (['executive_snapshot', 'platform_health', 'integration_health', 'critical_alerts'] as $zone) {
            $this->actingAs($viewer)
                ->getJson(route('admin.platform.zones.show', ['zone' => $zone]))
                ->assertOk();
        }
        $this->actingAs($viewer)->get(route('admin.platform.index'))->assertOk();

        $snapshot = app(PlatformHealthSnapshotService::class)->current();
        $this->assertNotNull($snapshot);

        $facts = [
            'mode' => 'active_queue_and_automation',
            'status' => $snapshot->status->value,
            'components' => array_map(
                static fn ($c) => ['key' => $c->key, 'status' => $c->status->value, 'detail' => $c->detail],
                $snapshot->components,
            ),
            'snapshot_writes' => array_values(array_filter(
                PlatformCacheAudit::entries(),
                static fn (array $e): bool => ($e['cache_key'] ?? '') === PlatformCachePolicy::KEY_PLATFORM_HEALTH_SNAPSHOT
                    && ($e['op'] ?? '') === 'put',
            )),
        ];

        file_put_contents(
            storage_path('logs/platform-cache-audit-facts-active.json'),
            json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function superadmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }
}
