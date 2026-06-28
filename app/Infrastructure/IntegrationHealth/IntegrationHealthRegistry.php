<?php

namespace App\Infrastructure\IntegrationHealth;

use App\Infrastructure\IntegrationHealth\Contracts\IntegrationHealthProbe;
use Illuminate\Support\Facades\Cache;

class IntegrationHealthRegistry
{
    private const CACHE_KEY = 'infrastructure:integration-health:latest';

    /** @var array<string, IntegrationHealthProbe> */
    private array $probes = [];

    public function register(IntegrationHealthProbe $probe): void
    {
        $this->probes[$probe->key()] = $probe;
    }

    /**
     * @return list<IntegrationHealthSnapshot>
     */
    public function probeAll(): array
    {
        return array_values(array_map(
            fn (IntegrationHealthProbe $probe): IntegrationHealthSnapshot => $probe->probe(),
            $this->probes,
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function captureAndCache(): array
    {
        $snapshots = array_map(
            fn (IntegrationHealthSnapshot $snapshot): array => $snapshot->toArray(),
            $this->probeAll(),
        );

        $payload = [
            'integrations' => $snapshots,
            'captured_at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_KEY, $payload, now()->addDay());

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestCached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }
}
