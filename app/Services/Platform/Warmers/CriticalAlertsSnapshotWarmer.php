<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class CriticalAlertsSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function __construct(
        PlatformZoneRegistry $zoneRegistry,
        PlatformCacheInvalidator $invalidator,
        private readonly PlatformOverallHealthService $overallHealth,
    ) {
        parent::__construct($zoneRegistry, $invalidator);
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            $this->overallHealth->store($this->overallHealth->compute());
            parent::warm($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale($this->zoneKey());
        }
    }
}
