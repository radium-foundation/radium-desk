<?php

namespace App\Contracts\Platform;

use App\Data\Platform\PlatformHealthContribution;

/**
 * Future zones register health contributions for overall Platform health.
 * Must be cache/snapshot only — never live probes.
 */
interface PlatformHealthContributor
{
    public function key(): string;

    public function label(): string;

    public function sortOrder(): int;

    public function contribute(): ?PlatformHealthContribution;
}
