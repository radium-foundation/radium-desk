<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class CacheHealthProvider implements PlatformHealthProvider
{
    public function key(): string
    {
        return 'cache';
    }

    public function label(): string
    {
        return 'Cache';
    }

    public function sortOrder(): int
    {
        return 60;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();
        $probeKey = 'platform-health:cache-probe:'.Str::random(8);
        $probeValue = (string) Str::uuid();

        try {
            $started = hrtime(true);
            Cache::put($probeKey, $probeValue, 30);
            $read = Cache::get($probeKey);
            Cache::forget($probeKey);
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            if ($read !== $probeValue) {
                return new PlatformHealthComponent(
                    key: $this->key(),
                    label: $this->label(),
                    status: PlatformHealthStatus::Critical,
                    detail: 'Cache round-trip returned unexpected value.',
                    checkedAt: $checkedAt,
                    metrics: [
                        'response_time_ms' => round($elapsedMs, 1),
                    ],
                );
            }

            if ($elapsedMs >= 200) {
                $status = PlatformHealthStatus::Warning;
                $detail = sprintf('Cache responded slowly (%.0f ms).', $elapsedMs);
            } else {
                $status = PlatformHealthStatus::Healthy;
                $detail = sprintf('Cache round-trip is healthy (%.0f ms).', $elapsedMs);
            }

            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: $status,
                detail: $detail,
                checkedAt: $checkedAt,
                metrics: [
                    'response_time_ms' => round($elapsedMs, 1),
                ],
            );
        } catch (Throwable $exception) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Critical,
                detail: 'Cache probe failed: '.$exception->getMessage(),
                checkedAt: $checkedAt,
            );
        }
    }
}
