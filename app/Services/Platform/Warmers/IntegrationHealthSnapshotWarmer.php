<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class IntegrationHealthSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformIntegrationHealthOverviewService $integrations,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('integration_health')) {
                $this->zoneRegistry->get('integration_health')->refresh($actor);

                return;
            }

            $this->integrations->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('integration_health');
        }
    }
}
