<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

abstract class AbstractZoneSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        protected readonly PlatformZoneRegistry $zoneRegistry,
        protected readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    abstract protected function zoneKey(): string;

    public function warm(?User $actor = null): void
    {
        $zoneKey = $this->zoneKey();

        if (! $this->zoneRegistry->has($zoneKey)) {
            return;
        }

        $zone = $this->zoneRegistry->get($zoneKey);

        if ($actor !== null && ! $zone->authorize($actor)) {
            return;
        }

        try {
            if ($actor === null) {
                // Snapshot/refresh paths that need a viewer: resolve later by concrete warmers.
                return;
            }

            $zone->refresh($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale($zoneKey);
        }
    }
}
