<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\Cards\PlatformHealthCardProvider;
use App\Services\Platform\PlatformCacheInvalidator;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Throwable;

class PlatformHealthSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function __construct(
        PlatformZoneRegistry $zoneRegistry,
        PlatformCacheInvalidator $invalidator,
        private readonly PlatformHealthCardProvider $healthCard,
    ) {
        parent::__construct($zoneRegistry, $invalidator);
    }

    public function key(): string
    {
        return 'platform_health';
    }

    public function label(): string
    {
        return 'Platform Health';
    }

    public function priority(): int
    {
        return 10;
    }

    protected function zoneKey(): string
    {
        return 'platform_health';
    }

    public function warm(?User $actor = null): void
    {
        $actor ??= PlatformWarmingActor::resolve();

        try {
            // Ensures platform:health:overview is written for Administration.
            $this->healthCard->refresh($actor);
            parent::warm($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidator->markZoneStale($this->zoneKey());
        }
    }
}
