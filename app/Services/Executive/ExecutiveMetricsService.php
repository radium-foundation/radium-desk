<?php

namespace App\Services\Executive;

use App\Contracts\Executive\ExecutiveMetricProvider;
use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Services\Executive\Trends\TrendService;

class ExecutiveMetricsService
{
    /** @var array<string, ExecutiveMetricsSnapshot> */
    private array $resolvedSnapshots = [];

    /**
     * @param  list<ExecutiveMetricProvider>  $providers
     */
    public function __construct(
        private readonly ExecutiveMetricsContextBuilder $contextBuilder,
        private readonly ExecutiveMetricsCache $cache,
        private readonly TrendService $trendService,
        private readonly array $providers,
    ) {}

    public function snapshot(
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
        bool $force = false,
    ): ExecutiveMetricsSnapshot {
        $dayKey = $this->dayKey($period);
        $memoryKey = $this->memoryKey($period, $dayKey);

        if (! $force && isset($this->resolvedSnapshots[$memoryKey])) {
            return $this->resolvedSnapshots[$memoryKey];
        }

        if (! $force) {
            $cached = $this->cache->get($period, $dayKey);

            if ($cached !== null) {
                $this->resolvedSnapshots[$memoryKey] = $cached;

                return $cached;
            }
        }

        $snapshot = $this->buildSnapshot($period);
        $this->cache->put($snapshot, $dayKey);
        $this->resolvedSnapshots[$memoryKey] = $snapshot;

        return $snapshot;
    }

    public function get(
        string $id,
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
        bool $force = false,
    ): ExecutiveMetricDto {
        return $this->snapshot($period, $force)->get($id);
    }

    public function refresh(
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
    ): ExecutiveMetricsSnapshot {
        return $this->snapshot($period, force: true);
    }

    private function buildSnapshot(ExecutiveMetricPeriod $period): ExecutiveMetricsSnapshot
    {
        $context = $this->contextBuilder->build($period);
        $metrics = [];

        foreach ($this->providers as $provider) {
            $metrics[] = $provider->fromContext($context);
        }

        $metrics = $this->trendService->enrich($metrics);

        return new ExecutiveMetricsSnapshot(
            period: $period,
            metrics: $metrics,
            generatedAt: $context->computedAt->copy(),
        );
    }

    private function dayKey(ExecutiveMetricPeriod $period): string
    {
        return match ($period) {
            ExecutiveMetricPeriod::Today => now()->toDateString(),
            ExecutiveMetricPeriod::Yesterday => now()->subDay()->toDateString(),
            ExecutiveMetricPeriod::Last7Days => now()->toDateString().'_7d',
        };
    }

    private function memoryKey(ExecutiveMetricPeriod $period, string $dayKey): string
    {
        return "{$period->value}:{$dayKey}";
    }
}
