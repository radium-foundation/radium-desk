<?php

namespace App\Contracts\Platform;

use App\Data\Platform\PlatformAlert;

/**
 * Zones/systems contribute alerts without CriticalAlertsZone knowing them.
 * Contributors must read caches/snapshots only — never live probes.
 */
interface PlatformAlertContributor
{
    public function key(): string;

    public function label(): string;

    public function sortOrder(): int;

    /**
     * @return list<PlatformAlert>
     */
    public function alerts(): array;
}
