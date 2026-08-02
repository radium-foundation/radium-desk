<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformCommunicationsOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class CommunicationsSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformCommunicationsOverviewService $communications,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
    ) {}

    public function key(): string
    {
        return 'communications';
    }

    public function label(): string
    {
        return 'Communications';
    }

    public function priority(): int
    {
        return 50;
    }

    public function warm(?User $actor = null): void
    {
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('communications')) {
                $this->zoneRegistry->get('communications')->refresh($actor);

                return;
            }

            $this->communications->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('communications');
        }
    }
}
