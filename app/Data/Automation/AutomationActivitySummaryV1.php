<?php

namespace App\Data\Automation;

/**
 * Compact activity-summary KPI slice for Operations System / advisor surfaces (v1).
 *
 * Does not include feed rows — only shared execution counts from the ledger read model.
 */
readonly class AutomationActivitySummaryV1
{
    public function __construct(
        public int $executionsToday,
        public int $failuresToday,
        public int $pendingExecutions,
        public ?int $averageExecutionMs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'executions_today' => $this->executionsToday,
            'failures_today' => $this->failuresToday,
            'pending_executions' => $this->pendingExecutions,
            'average_execution_ms' => $this->averageExecutionMs,
        ];
    }
}
