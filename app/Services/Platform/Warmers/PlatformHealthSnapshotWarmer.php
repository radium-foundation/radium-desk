<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\Cards\PlatformHealthCardProvider;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use App\Services\Platform\Zones\PlatformZoneSnapshotStore;
use Throwable;

class PlatformHealthSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function __construct(
        PlatformZoneRegistry $zoneRegistry,
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformHealthCardProvider $healthCard,
    ) {
        parent::__construct($zoneRegistry, $snapshotStore);
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
        if ($actor === null) {
            return;
        }

        try {
            // Ensures platform:health:overview is written even if zone refresh is skipped.
            $this->healthCard->refresh($actor);
            parent::warm($actor);
        } catch (Throwable $exception) {
            report($exception);
            $this->snapshotStore->markStale($this->zoneKey());
        }
    }
}
