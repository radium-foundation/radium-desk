<?php

namespace App\Services\Platform\Health;

use App\Data\Platform\PlatformHealthComponent;
use App\Data\Platform\PlatformHealthSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\PlatformHealthRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for infra Platform Health.
 *
 * Platform Health zone, Critical Alerts, Overall Health, and Watchdog/Telegram
 * all consume this snapshot object — probes run once per refresh/warm.
 */
class PlatformHealthSnapshotService
{
    public const CACHE_KEY = PlatformCachePolicy::KEY_PLATFORM_HEALTH_SNAPSHOT;

    public function __construct(
        private readonly PlatformHealthRegistry $registry,
        private readonly PlatformZoneSnapshotStore $zoneSnapshotStore,
    ) {}

    public function probe(): PlatformHealthSnapshot
    {
        $components = $this->registry->probeAll();
        $status = self::aggregateOverall($components);

        $snapshot = new PlatformHealthSnapshot(
            status: $status,
            statusLabel: $status->label(),
            components: $components,
            generatedAt: now(),
            stale: false,
            available: true,
        );

        $this->store($snapshot);

        return $snapshot;
    }

    /**
     * Overall Platform Health from enabled infrastructure only.
     *
     * Disabled components stay on their cards for information, but must not
     * degrade the shared snapshot overall status.
     *
     * @param  list<PlatformHealthComponent>  $components
     */
    public static function aggregateOverall(array $components): PlatformHealthStatus
    {
        if ($components === []) {
            return PlatformHealthStatus::Disabled;
        }

        $active = array_values(array_filter(
            $components,
            static fn (PlatformHealthComponent $component): bool => $component->status !== PlatformHealthStatus::Disabled,
        ));

        if ($active === []) {
            return PlatformHealthStatus::Disabled;
        }

        return PlatformHealthStatus::worst(
            ...array_map(
                static fn (PlatformHealthComponent $component): PlatformHealthStatus => $component->status,
                $active,
            ),
        );
    }

    public function current(bool $useCache = true): ?PlatformHealthSnapshot
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);
            PlatformCacheAudit::read(
                service: self::class,
                method: 'current',
                cacheKey: self::CACHE_KEY,
                payload: is_array($cached) ? $cached : null,
                hit: is_array($cached),
            );
            if (is_array($cached)) {
                return PlatformHealthSnapshot::fromArray($cached);
            }
        }

        return null;
    }

    public function currentOrProbe(bool $useCache = true): PlatformHealthSnapshot
    {
        if ($useCache) {
            $cached = $this->current(true);
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->probe();
    }

    public function store(PlatformHealthSnapshot $snapshot): void
    {
        $ttl = now()->addSeconds(PlatformCachePolicy::TTL_PRIORITY_1);
        $new = $snapshot->toArray();
        $old = Cache::get(self::CACHE_KEY);

        PlatformCacheAudit::write(
            service: self::class,
            method: 'store',
            cacheKey: self::CACHE_KEY,
            oldPayload: is_array($old) ? $old : null,
            newPayload: $new,
        );

        Cache::put(self::CACHE_KEY, $new, $ttl);

        $overview = $snapshot->toOverviewArray();
        $oldOverview = Cache::get(PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW);
        PlatformCacheAudit::write(
            service: self::class,
            method: 'store',
            cacheKey: PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW,
            oldPayload: is_array($oldOverview) ? $oldOverview : null,
            newPayload: $overview,
        );
        Cache::put(
            PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW,
            $overview,
            $ttl,
        );

        // Dependents must rebuild from this object — never keep a stale Critical Alerts bake.
        $this->zoneSnapshotStore->forget('critical_alerts');
        PlatformCacheAudit::forget(self::class, 'store', PlatformCachePolicy::KEY_OVERALL_HEALTH);
        Cache::forget(PlatformCachePolicy::KEY_OVERALL_HEALTH);
    }

    public function forget(): void
    {
        PlatformCacheAudit::forget(self::class, 'forget', self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY);
        PlatformCacheAudit::forget(self::class, 'forget', PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW);
        Cache::forget(PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW);
        $this->zoneSnapshotStore->forget('critical_alerts');
        PlatformCacheAudit::forget(self::class, 'forget', PlatformCachePolicy::KEY_OVERALL_HEALTH);
        Cache::forget(PlatformCachePolicy::KEY_OVERALL_HEALTH);
    }
}
