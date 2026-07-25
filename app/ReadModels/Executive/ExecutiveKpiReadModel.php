<?php

namespace App\ReadModels\Executive;

use App\Data\Executive\ExecutiveKpiMetricsV1;
use App\Data\Executive\ExecutiveMetricDto;
use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsSnapshot;
use App\Services\Executive\ExecutiveMetricsService;

/**
 * Read-only projection over ExecutiveMetricsService.
 *
 * - No SQL, KPI formulas, thresholds, or business rules.
 * - No cache layer — ExecutiveMetricsCache (60s) remains on the owner.
 * - Methods pure-delegate so Mission Control call sequences stay identical.
 */
class ExecutiveKpiReadModel
{
    public function __construct(
        private readonly ExecutiveMetricsService $metricsService,
    ) {}

    /**
     * Scalar projection of the eight existing executive KPIs.
     */
    public function metrics(
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
        bool $force = false,
    ): ExecutiveKpiMetricsV1 {
        return ExecutiveKpiMetricsV1::fromSnapshot(
            $this->metricsService->snapshot($period, $force),
        );
    }

    public function snapshot(
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
        bool $force = false,
    ): ExecutiveMetricsSnapshot {
        return $this->metricsService->snapshot($period, $force);
    }

    public function get(
        string $id,
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
        bool $force = false,
    ): ExecutiveMetricDto {
        return $this->metricsService->get($id, $period, $force);
    }

    public function refresh(
        ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today,
    ): ExecutiveMetricsSnapshot {
        return $this->metricsService->refresh($period);
    }
}
