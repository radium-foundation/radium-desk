<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformOperationsOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class OperationsSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformOperationsOverviewService $operations,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'operations_overview';
    }

    public function label(): string
    {
        return 'Operations Overview';
    }

    public function priority(): int
    {
        return 60;
    }

    public function warm(?User $actor = null): void
    {
        try {
            $this->operations->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('operations_overview')) {
                $zone = $this->zoneRegistry->get('operations_overview');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('operations_overview');
        }
    }
}
