<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformPerformanceOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class PerformanceSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformPerformanceOverviewService $performance,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('performance')) {
                $this->zoneRegistry->get('performance')->refresh($actor);

                return;
            }

            $this->performance->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('performance');
        }
    }
}
