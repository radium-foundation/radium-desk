<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

abstract class AbstractZoneSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        protected readonly PlatformZoneRegistry $zoneRegistry,
        protected readonly PlatformCacheInvalidator $invalidator,
    ) {}

    abstract protected function zoneKey(): string;

    public function warm(?User $actor = null): void
    {
        $zoneKey = $this->zoneKey();

        if (! $this->zoneRegistry->has($zoneKey)) {
            return;
        }

        $actor ??= PlatformWarmingActor::resolve();
        $zone = $this->zoneRegistry->get($zoneKey);

        try {
            // Actor-independent: shared zone HTML is RBAC-filtered on read, not write.
            $zone->refresh($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale($zoneKey);
        }
    }
}
