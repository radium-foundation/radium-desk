<?php

namespace App\Contracts\Platform;

use App\Data\Platform\PlatformHealthComponent;

interface PlatformHealthProvider
{
    public function key(): string;

    public function label(): string;

    public function sortOrder(): int;

    public function probe(): PlatformHealthComponent;
}
