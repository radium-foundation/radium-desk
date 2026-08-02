<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformPerformanceOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class PerformanceSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformPerformanceOverviewService $performance,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'performance';
    }

    public function label(): string
    {
        return 'Performance';
    }

    public function priority(): int
    {
        return 40;
    }

    public function warm(?User $actor = null): void
    {
        try {
            $this->performance->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('performance')) {
                $zone = $this->zoneRegistry->get('performance');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('performance');
        }
    }
}
