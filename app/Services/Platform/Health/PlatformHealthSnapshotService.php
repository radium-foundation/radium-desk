<?php

namespace App\Services\Platform\Health;

use App\Data\Platform\PlatformHealthComponent;
use App\Data\Platform\PlatformHealthSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Services\Platform\PlatformCachePolicy;
use App\Services\Platform\PlatformHealthRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
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
        $status = $components === []
            ? PlatformHealthStatus::Disabled
            : PlatformHealthStatus::worst(
                ...array_map(
                    static fn (PlatformHealthComponent $component): PlatformHealthStatus => $component->status,
                    $components,
                ),
            );

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

    public function current(bool $useCache = true): ?PlatformHealthSnapshot
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);
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

        Cache::put(self::CACHE_KEY, $snapshot->toArray(), $ttl);
        Cache::put(
            PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW,
            $snapshot->toOverviewArray(),
            $ttl,
        );

        // Dependents must rebuild from this object — never keep a stale Critical Alerts bake.
        $this->zoneSnapshotStore->forget('critical_alerts');
        Cache::forget(PlatformCachePolicy::KEY_OVERALL_HEALTH);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(PlatformCachePolicy::KEY_PLATFORM_HEALTH_OVERVIEW);
        $this->zoneSnapshotStore->forget('critical_alerts');
        Cache::forget(PlatformCachePolicy::KEY_OVERALL_HEALTH);
    }
}
