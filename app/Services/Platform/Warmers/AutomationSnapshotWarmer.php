<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformAutomationOverviewService;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class AutomationSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformAutomationOverviewService $automation,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
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
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('automation')) {
                $this->zoneRegistry->get('automation')->refresh($actor);

                return;
            }

            $this->automation->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('automation');
        }
    }
}
