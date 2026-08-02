<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class CriticalAlertsSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function __construct(
        PlatformZoneRegistry $zoneRegistry,
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformOverallHealthService $overallHealth,
    ) {
        parent::__construct($zoneRegistry, $snapshotStore);
    }

    public function key(): string
    {
        return 'critical_alerts';
    }

    public function label(): string
    {
        return 'Critical Alerts';
    }

    public function priority(): int
    {
        return 40;
    }

    protected function zoneKey(): string
    {
        return 'critical_alerts';
    }

    public function warm(?User $actor = null): void
    {
        try {
            // Recompute overall health from caches after Priority-1 warmers.
            $this->overallHealth->store($this->overallHealth->compute());
            parent::warm($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale($this->zoneKey());
        }
    }
}
