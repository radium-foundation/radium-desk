<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;

class PlatformHealthSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
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
        // Zone refresh probes once via PlatformHealthCardProvider and writes
        // platform:health:snapshot + platform:health:overview for Administration.
        parent::warm($actor);
    }
}
