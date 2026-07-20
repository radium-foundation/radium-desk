<?php

namespace App\Services\Platform;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use InvalidArgumentException;

class PlatformHealthRegistry
{
    /** @var array<string, PlatformHealthProvider> */
    private array $providers = [];

    public function register(PlatformHealthProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): PlatformHealthProvider
    {
        if (! isset($this->providers[$key])) {
            throw new InvalidArgumentException("Unknown platform health provider [{$key}].");
        }

        return $this->providers[$key];
    }

    /**
     * @return list<PlatformHealthProvider>
     */
    public function all(): array
    {
        $providers = array_values($this->providers);

        usort(
            $providers,
            fn (PlatformHealthProvider $a, PlatformHealthProvider $b): int => $a->sortOrder() <=> $b->sortOrder(),
        );

        return $providers;
    }

    /**
     * @return list<PlatformHealthComponent>
     */
    public function probeAll(): array
    {
        return array_map(
            fn (PlatformHealthProvider $provider): PlatformHealthComponent => $provider->probe(),
            $this->all(),
        );
    }
}
