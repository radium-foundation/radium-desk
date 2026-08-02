<?php

namespace App\Services\Platform\Zones;

use App\Contracts\Platform\PlatformZone;
use InvalidArgumentException;

class PlatformZoneRegistry
{
    /** @var array<string, PlatformZone> */
    private array $zones = [];

    public function register(PlatformZone $zone): void
    {
        $this->zones[$zone->definition()->key()] = $zone;
    }

    public function has(string $key): bool
    {
        return isset($this->zones[$key]);
    }

    public function get(string $key): PlatformZone
    {
        if (! isset($this->zones[$key])) {
            throw new InvalidArgumentException("Unknown platform zone [{$key}].");
        }

        return $this->zones[$key];
    }

    /**
     * @return list<PlatformZone>
     */
    public function all(): array
    {
        $zones = array_values($this->zones);

        usort(
            $zones,
            static fn (PlatformZone $a, PlatformZone $b): int => $a->definition()->sortOrder <=> $b->definition()->sortOrder,
        );

        return $zones;
    }

    /**
     * @return list<PlatformZone>
     */
    public function authorizedFor(\App\Models\User $viewer): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (PlatformZone $zone): bool => $zone->authorize($viewer),
        ));
    }
}
