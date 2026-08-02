<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformOperationsOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class OperationsSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformOperationsOverviewService $operations,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('operations_overview')) {
                $this->zoneRegistry->get('operations_overview')->refresh($actor);

                return;
            }

            $this->operations->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('operations_overview');
        }
    }
}
