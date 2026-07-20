<?php

namespace App\Services\Platform;

use App\Contracts\Platform\PlatformCardProvider;
use InvalidArgumentException;

class PlatformCardRegistry
{
    /** @var array<string, PlatformCardProvider> */
    private array $providers = [];

    public function register(PlatformCardProvider $provider): void
    {
        $this->providers[$provider->definition()->id] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): PlatformCardProvider
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown platform card provider [{$key}].");
        }

        return $this->providers[$key];
    }

    /**
     * @return list<PlatformCardProvider>
     */
    public function all(): array
    {
        $providers = array_values($this->providers);

        usort(
            $providers,
            function (PlatformCardProvider $a, PlatformCardProvider $b): int {
                $aDefinition = $a->definition();
                $bDefinition = $b->definition();

                if ($aDefinition->pinned !== $bDefinition->pinned) {
                    return $bDefinition->pinned <=> $aDefinition->pinned;
                }

                return $aDefinition->sortKey() <=> $bDefinition->sortKey();
            },
        );

        return $providers;
    }
}
