<?php

namespace App\Services\Executive;

use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use Illuminate\Support\Facades\Cache;

class ExecutiveMetricsCache
{
    private const TTL_SECONDS = 60;

    public function key(ExecutiveMetricPeriod $period, string $dayKey): string
    {
        return "executive:metrics:snapshot:{$period->value}:{$dayKey}";
    }

    public function get(ExecutiveMetricPeriod $period, string $dayKey): ?ExecutiveMetricsSnapshot
    {
        $cached = Cache::get($this->key($period, $dayKey));

        if (! is_array($cached)) {
            return null;
        }

        return ExecutiveMetricsSnapshot::fromArray($cached);
    }

    public function put(ExecutiveMetricsSnapshot $snapshot, string $dayKey): void
    {
        Cache::put(
            $this->key($snapshot->period, $dayKey),
            $snapshot->toArray(),
            self::TTL_SECONDS,
        );
    }

    public function forget(ExecutiveMetricPeriod $period, string $dayKey): void
    {
        Cache::forget($this->key($period, $dayKey));
    }
}
