<?php

namespace App\Data\Automation;

/**
 * Shared automation execution ledger KPIs (v1).
 *
 * Sourced from AutomationHealthService overview aggregation (H4-2 cache).
 */
readonly class AutomationExecutionMetricsV1
{
    public function __construct(
        public int $executionsToday,
        public int $successToday,
        public int $failuresToday,
        public int $skippedToday,
        public int $pendingExecutions,
        public ?int $averageExecutionMs,
    ) {}

    /**
     * @param  array<string, mixed>  $overview
     */
    public static function fromOverview(array $overview): self
    {
        return new self(
            executionsToday: (int) ($overview['executions_today'] ?? 0),
            successToday: (int) ($overview['success_today'] ?? 0),
            failuresToday: (int) ($overview['failures_today'] ?? 0),
            skippedToday: (int) ($overview['skipped_today'] ?? 0),
            pendingExecutions: (int) ($overview['pending_executions'] ?? 0),
            averageExecutionMs: isset($overview['average_execution_ms']) && is_numeric($overview['average_execution_ms'])
                ? (int) $overview['average_execution_ms']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'executions_today' => $this->executionsToday,
            'success_today' => $this->successToday,
            'failures_today' => $this->failuresToday,
            'skipped_today' => $this->skippedToday,
            'pending_executions' => $this->pendingExecutions,
            'average_execution_ms' => $this->averageExecutionMs,
        ];
    }
}
