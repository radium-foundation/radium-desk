<?php

namespace Tests\Unit\Platform;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Services\Platform\PlatformHealthRegistry;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformHealthRegistryTest extends TestCase
{
    public function test_registry_returns_providers_in_sort_order(): void
    {
        $registry = new PlatformHealthRegistry;
        $registry->register($this->makeProvider('beta', 20));
        $registry->register($this->makeProvider('alpha', 10));

        $keys = array_map(
            fn (PlatformHealthProvider $provider): string => $provider->key(),
            $registry->all(),
        );

        $this->assertSame(['alpha', 'beta'], $keys);
    }

    public function test_probe_all_returns_component_snapshots(): void
    {
        $registry = new PlatformHealthRegistry;
        $registry->register($this->makeProvider('scheduler', 10, PlatformHealthStatus::Warning));

        $components = $registry->probeAll();

        $this->assertCount(1, $components);
        $this->assertSame('scheduler', $components[0]->key);
        $this->assertSame(PlatformHealthStatus::Warning, $components[0]->status);
    }

    private function makeProvider(
        string $key,
        int $sortOrder,
        PlatformHealthStatus $status = PlatformHealthStatus::Healthy,
    ): PlatformHealthProvider {
        return new class($key, $sortOrder, $status) implements PlatformHealthProvider
        {
            public function __construct(
                private readonly string $providerKey,
                private readonly int $order,
                private readonly PlatformHealthStatus $status,
            ) {}

            public function key(): string
            {
                return $this->providerKey;
            }

            public function label(): string
            {
                return ucfirst($this->providerKey);
            }

            public function sortOrder(): int
            {
                return $this->order;
            }

            public function probe(): PlatformHealthComponent
            {
                return new PlatformHealthComponent(
                    key: $this->providerKey,
                    label: $this->label(),
                    status: $this->status,
                    detail: 'ok',
                    checkedAt: Carbon::parse('2026-07-20 11:00:00', 'Asia/Kolkata'),
                );
            }
        };
    }
}
