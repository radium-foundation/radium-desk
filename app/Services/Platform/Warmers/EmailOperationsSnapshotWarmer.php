<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;
use App\Models\User;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\PlatformEmailOperationsService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class EmailOperationsSnapshotWarmer implements PlatformSnapshotWarmer
{
    public function __construct(
        private readonly PlatformEmailOperationsService $emailOperations,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PlatformCacheInvalidator $invalidator,
    ) {}

    public function key(): string
    {
        return 'email_operations';
    }

    public function label(): string
    {
        return 'Email Operations';
    }

    public function priority(): int
    {
        return 52;
    }

    public function warm(?User $actor = null): void
    {
        $actor ??= PlatformWarmingActor::resolve();

        try {
            if ($this->zoneRegistry->has('email_operations')) {
                $this->zoneRegistry->get('email_operations')->refresh($actor);

                return;
            }

            $this->emailOperations->overview(useCache: false);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale('email_operations');
        }
    }
}
