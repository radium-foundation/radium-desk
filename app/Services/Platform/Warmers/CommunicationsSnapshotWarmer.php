<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCommunicationsOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class CommunicationsSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformCommunicationsOverviewService $communications,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'communications';
    }

    public function label(): string
    {
        return 'Communications';
    }

    public function priority(): int
    {
        return 50;
    }

    public function warm(?User $actor = null): void
    {
        try {
            // Prefer Integration Health caches; may trigger overview refresh if cold.
            $this->communications->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('communications')) {
                $zone = $this->zoneRegistry->get('communications');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('communications');
        }
    }
}
