<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class IntegrationHealthSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrations,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    public function key(): string
    {
        return 'integration_health';
    }

    public function label(): string
    {
        return 'Integration Health';
    }

    public function priority(): int
    {
        return 30;
    }

    public function warm(?User $actor = null): void
    {
        try {
            $this->integrations->overview(useCache: false);

            if ($actor !== null && $this->zoneRegistry->has('integration_health')) {
                $zone = $this->zoneRegistry->get('integration_health');
                if ($zone->authorize($actor)) {
                    $zone->refresh($actor);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale('integration_health');
        }
    }
}
