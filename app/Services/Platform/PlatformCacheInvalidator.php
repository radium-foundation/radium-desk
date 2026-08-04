<?php

namespace App\Services\Platform;

use App\Enums\PlatformZoneId;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use App\Support\Platform\PlatformCacheAudit;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps zone snapshots and related overview/overall-health keys coherent.
 */
final class PlatformCacheInvalidator
{
    public function __construct(
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function invalidateZone(string $zoneKey): void
    {
        $this->snapshotStore->forget($zoneKey);

        foreach (PlatformCachePolicy::relatedOverviewKeys($zoneKey) as $key) {
            PlatformCacheAudit::forget(self::class, 'invalidateZone', $key);
            Cache::forget($key);
        }

        foreach (PlatformCachePolicy::dependentZones($zoneKey) as $dependent) {
            $this->snapshotStore->forget($dependent);
        }

        if ($zoneKey === PlatformZoneId::IntegrationHealth->value) {
            $this->forgetIntegrationItems();
        }
    }

    public function markZoneStale(string $zoneKey): void
    {
        $this->snapshotStore->markStale($zoneKey);

        // Drop short-lived overviews so next warm rebuilds consistently with zone HTML.
        foreach (PlatformCachePolicy::relatedOverviewKeys($zoneKey) as $key) {
            if ($key === PlatformCachePolicy::KEY_OVERALL_HEALTH) {
                continue;
            }
            PlatformCacheAudit::forget(self::class, 'markZoneStale', $key);
            Cache::forget($key);
        }

        foreach (PlatformCachePolicy::dependentZones($zoneKey) as $dependent) {
            $this->snapshotStore->forget($dependent);
        }
    }

    /**
     * After a contributor zone refreshes successfully, drop Critical Alerts so it rebuilds.
     */
    public function invalidateDependents(string $zoneKey): void
    {
        foreach (PlatformCachePolicy::dependentZones($zoneKey) as $dependent) {
            $this->snapshotStore->forget($dependent);
        }

        if (PlatformCachePolicy::dependentZones($zoneKey) !== []) {
            PlatformCacheAudit::forget(self::class, 'invalidateDependents', PlatformCachePolicy::KEY_OVERALL_HEALTH);
            Cache::forget(PlatformCachePolicy::KEY_OVERALL_HEALTH);
        }
    }

    private function forgetIntegrationItems(): void
    {
        foreach (['radiumbox', 'cashfree', 'gmail', 'interakt', 'telegram', 'zeptomail', 'meta'] as $item) {
            $key = PlatformCachePolicy::KEY_INTEGRATION_ITEM_PREFIX.$item;
            PlatformCacheAudit::forget(self::class, 'forgetIntegrationItems', $key);
            Cache::forget($key);
        }
    }
}
