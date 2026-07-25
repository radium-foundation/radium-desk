<?php

namespace App\ReadModels\Cases;

use App\Data\Cases\CaseQueueMetricsV1;
use App\Enums\OperationQueue;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;

/**
 * Explicit count scope over CaseQueueReadModel (H4-6D).
 *
 * Pure delegate — no SQL, cache, membership, or formulas.
 * Global scope uses null user; user scope passes the assignee filter to DashboardSnapshot.
 */
final class CaseQueueScope
{
    public function __construct(
        private readonly CaseQueueReadModel $readModel,
        private readonly ?User $scopeUser,
        private readonly ?DashboardSnapshot $snapshot = null,
    ) {}

    public function openCount(): int
    {
        return $this->readModel->openCount($this->scopeUser, $this->snapshot);
    }

    public function waitingCount(): int
    {
        return $this->readModel->waitingCount($this->snapshot);
    }

    /**
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        return $this->readModel->queueCounts($this->scopeUser, $this->snapshot);
    }

    public function queueCount(OperationQueue|string $queue): int
    {
        return $this->readModel->queueCount($queue, $this->scopeUser, $this->snapshot);
    }

    /**
     * @return array{open_cases: int, waiting_cases: int}
     */
    public function operationalKpiCounts(): array
    {
        return $this->readModel->operationalKpiCounts($this->scopeUser, $this->snapshot);
    }

    public function metrics(): CaseQueueMetricsV1
    {
        return $this->readModel->metrics($this->scopeUser, $this->snapshot);
    }
}
