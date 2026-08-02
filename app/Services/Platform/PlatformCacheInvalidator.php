<?php

namespace App\Services\Platform;

use App\Enums\PlatformZoneId;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
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
            Cache::forget($key);
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
            Cache::forget($key);
        }
    }

    private function forgetIntegrationItems(): void
    {
        foreach (['radiumbox', 'cashfree', 'gmail', 'interakt', 'telegram', 'zeptomail', 'meta'] as $item) {
            Cache::forget(PlatformCachePolicy::KEY_INTEGRATION_ITEM_PREFIX.$item);
        }
    }
}
