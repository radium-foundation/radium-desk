<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthContributor;

class PlatformOverallHealthRegistry
{
    /** @var array<string, PlatformHealthContributor> */
    private array $contributors = [];

    public function register(PlatformHealthContributor $contributor): void
    {
        $this->contributors[$contributor->key()] = $contributor;
    }

    /**
     * @return list<PlatformHealthContributor>
     */
    public function all(): array
    {
        $contributors = array_values($this->contributors);

        usort(
            $contributors,
            static fn (PlatformHealthContributor $a, PlatformHealthContributor $b): int => $a->sortOrder() <=> $b->sortOrder(),
        );

        return $contributors;
    }
}
