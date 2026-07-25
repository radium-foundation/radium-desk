<?php

namespace App\Data\Cases;

use App\Enums\OperationQueue;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;

/**
 * Immutable case-queue KPI scalars (v1).
 *
 * Projected from DashboardSnapshot — facts only, no formulas.
 * Includes only existing open/waiting, OperationQueue counts, and SLA overlays.
 */
readonly class CaseQueueMetricsV1
{
    /**
     * @param  array<string, int>  $queueCounts  keyed by OperationQueue value
     * @param  array{
     *     overdue_cases: int,
     *     warning_cases: int,
     *     service_overdue_cases: int,
     *     service_warning_cases: int,
     *     hardware_overdue_cases: int,
     *     hardware_warning_cases: int
     * }  $slaCounts
     */
    public function __construct(
        public int $openCases,
        public int $waitingCases,
        public array $queueCounts,
        public array $slaCounts,
    ) {}

    public static function fromSnapshot(DashboardSnapshot $snapshot, ?User $scopeUser = null): self
    {
        $operational = $snapshot->operationalKpiCounts($scopeUser);

        return new self(
            openCases: (int) ($operational['open_cases'] ?? 0),
            waitingCases: (int) ($operational['waiting_cases'] ?? 0),
            queueCounts: $snapshot->queueCounts($scopeUser),
            slaCounts: $snapshot->slaCounts(),
        );
    }

    public function queueCount(OperationQueue|string $queue): int
    {
        $key = $queue instanceof OperationQueue ? $queue->value : $queue;

        return (int) ($this->queueCounts[$key] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'open_cases' => $this->openCases,
            'waiting_cases' => $this->waitingCases,
            'queue_counts' => $this->queueCounts,
            'sla_counts' => $this->slaCounts,
        ];
    }
}
