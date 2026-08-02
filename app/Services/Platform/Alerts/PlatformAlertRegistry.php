<?php

namespace App\Services\Platform\Alerts;

use App\Contracts\Platform\PlatformAlertContributor;

class PlatformAlertRegistry
{
    /** @var array<string, PlatformAlertContributor> */
    private array $contributors = [];

    public function register(PlatformAlertContributor $contributor): void
    {
        $this->contributors[$contributor->key()] = $contributor;
    }

    public function has(string $key): bool
    {
        return isset($this->contributors[$key]);
    }

    /**
     * @return list<PlatformAlertContributor>
     */
    public function all(): array
    {
        $contributors = array_values($this->contributors);

        usort(
            $contributors,
            static fn (PlatformAlertContributor $a, PlatformAlertContributor $b): int => $a->sortOrder() <=> $b->sortOrder(),
        );

        return $contributors;
    }
}
