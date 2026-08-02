<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformAutomationOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class AutomationSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformAutomationOverviewService $automation,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'automation';
    }

    public function label(): string
    {
        return 'Automation';
    }

    public function priority(): int
    {
        return 45;
    }

    public function warm(?User $actor = null): void
    {
        try {
            $this->automation->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('automation')) {
                $zone = $this->zoneRegistry->get('automation');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('automation');
        }
    }
}
