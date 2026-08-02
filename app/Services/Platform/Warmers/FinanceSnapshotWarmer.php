<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformFinanceOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class FinanceSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformFinanceOverviewService $finance,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('finance_overview')) {
                $this->zoneRegistry->get('finance_overview')->refresh($actor);

                return;
            }

            $this->finance->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('finance_overview');
        }
    }
}
