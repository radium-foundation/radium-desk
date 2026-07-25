<?php

namespace App\ReadModels\Automation;

use App\Data\Automation\AutomationActivitySummaryV1;
use App\Data\Automation\AutomationExecutionMetricsV1;
use App\Services\Operations\AutomationHealthService;

/**
 * Read-only facade over AutomationHealthService execution ledger KPIs.
 *
 * Business logic and SQL remain in AutomationHealthService. This class only
 * projects cached overview aggregation into versioned DTOs for consumers.
 *
 * Cache: reuses H4-2 owner cache `operations:automation-health:aggregation:{date}` (60s TTL).
 * No nested cache layer.
 */
class AutomationExecutionReadModel
{
    public function __construct(
        private readonly AutomationHealthService $healthService,
    ) {}

    /**
     * Shared execution counts, failures, and average duration.
     */
    public function metrics(): AutomationExecutionMetricsV1
    {
        return AutomationExecutionMetricsV1::fromOverview(
            $this->healthService->overviewAggregation(),
        );
    }

    /**
     * Compact summary for activity / advisor supporting metrics.
     */
    public function activitySummary(): AutomationActivitySummaryV1
    {
        $metrics = $this->metrics();

        return new AutomationActivitySummaryV1(
            executionsToday: $metrics->executionsToday,
            failuresToday: $metrics->failuresToday,
            pendingExecutions: $metrics->pendingExecutions,
            averageExecutionMs: $metrics->averageExecutionMs,
        );
    }

    /**
     * Full Automation Health overview array (same cached aggregation as the standalone page).
     *
     * @return array<string, mixed>
     */
    public function healthOverview(): array
    {
        return $this->healthService->overviewAggregation();
    }
}
