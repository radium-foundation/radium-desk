<?php

namespace App\Services\Platform;

use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformDashboardData;
use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Data\Platform\PlatformZoneViewData;
use App\Models\User;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use InvalidArgumentException;

class PlatformDashboardService
{
    public function __construct(
        private readonly DashboardManifest $manifest,
        private readonly PlatformZoneRegistry $zoneRegistry,
    ) {}

    public function build(User $viewer): PlatformDashboardData
    {
        return $this->manifest->resolve($viewer);
    }

    /**
     * First-paint zone payloads (snapshot/cache only for expensive zones).
     *
     * @return list<PlatformZoneViewData>
     */
    public function zoneSnapshots(User $viewer): array
    {
        $zones = [];

        foreach ($this->zoneRegistry->authorizedFor($viewer) as $zone) {
            $zones[] = new PlatformZoneViewData(
                definition: $zone->definition(),
                snapshot: $zone->snapshot($viewer),
            );
        }

        return $zones;
    }

    public function refreshZone(User $viewer, string $zoneKey): PlatformZoneSnapshot
    {
        $zone = $this->zoneRegistry->get($zoneKey);

        if (! $zone->authorize($viewer)) {
            throw new InvalidArgumentException("Unauthorized platform zone [{$zoneKey}].");
        }

        return $zone->refresh($viewer);
    }

    public function expandZone(User $viewer, string $zoneKey, string $item): ?PlatformZoneExpandResult
    {
        $zone = $this->zoneRegistry->get($zoneKey);

        if (! $zone->authorize($viewer)) {
            throw new InvalidArgumentException("Unauthorized platform zone [{$zoneKey}].");
        }

        return $zone->expand($viewer, $item);
    }

    public function cardPayload(User $viewer, string $cardKey): PlatformCardPayload
    {
        return $this->manifest->cardPayload($viewer, $cardKey);
    }
}
