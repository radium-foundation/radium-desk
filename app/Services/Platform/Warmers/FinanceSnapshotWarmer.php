<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformFinanceOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class FinanceSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformFinanceOverviewService $finance,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'finance_overview';
    }

    public function label(): string
    {
        return 'Finance Overview';
    }

    public function priority(): int
    {
        return 55;
    }

    public function warm(?User $actor = null): void
    {
        try {
            $this->finance->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('finance_overview')) {
                $zone = $this->zoneRegistry->get('finance_overview');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('finance_overview');
        }
    }
}
