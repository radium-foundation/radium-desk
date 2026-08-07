<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;

class CriticalAlertsSnapshotWarmer extends AbstractZoneSnapshotWarmer
{
    public function key(): string
    {
        return 'critical_alerts';
    }

    public function label(): string
    {
        return 'Critical Alerts';
    }

    public function priority(): int
    {
        return 40;
    }

    protected function zoneKey(): string
    {
        return 'critical_alerts';
    }

    public function warm(?User $actor = null): void
    {
        // CriticalAlertsZone::buildFreshSnapshot stores overall health once.
        parent::warm($actor);
    }
}
