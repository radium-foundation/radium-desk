<?php

namespace App\Services\Platform;

use App\Data\Platform\PlatformSectionDefinition;
use InvalidArgumentException;

class PlatformSectionRegistry
{
    /** @var array<string, PlatformSectionDefinition> */
    private array $sections = [];

    public function register(PlatformSectionDefinition $section): void
    {
        $this->sections[$section->id] = $section;
    }

    public function has(string $id): bool
    {
        return isset($this->sections[$id]);
    }

    public function get(string $id): PlatformSectionDefinition
    {
        if (! isset($this->sections[$id])) {
            throw new InvalidArgumentException("Unknown platform section [{$id}].");
        }

        return $this->sections[$id];
    }

    /**
     * @return list<PlatformSectionDefinition>
     */
    public function ordered(): array
    {
        $sections = array_values($this->sections);

        usort(
            $sections,
            fn (PlatformSectionDefinition $a, PlatformSectionDefinition $b): int => $a->priority <=> $b->priority,
        );

        return $sections;
    }
}
