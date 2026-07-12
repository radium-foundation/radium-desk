<?php

namespace App\Services\Operations;

use App\Enums\OperationsHealthStatus;
use Illuminate\Support\Carbon;

class AutomationHealthStatusCalculator
{
    /**
     * @return array{status: OperationsHealthStatus, label: string, badge_class: string, detail: string}
     */
    public function calculate(
        ?Carbon $lastSuccessAt,
        ?Carbon $lastExecutionAt,
        int $failuresToday,
        int $pendingCount,
        ?Carbon $oldestPendingStartedAt,
    ): array {
        $stallMinutes = max(1, (int) config('operations.automation_health.stall_threshold_minutes', 120));
        $warningSuccessAgeMinutes = max(1, (int) config('operations.automation_health.warning_success_age_minutes', 60));

        if ($this->isSchedulerStalled($lastExecutionAt, $stallMinutes, $pendingCount, $oldestPendingStartedAt)) {
            return $this->result(
                OperationsHealthStatus::Failed,
                'Scheduler appears stalled — no recent automation activity.',
            );
        }

        if ($failuresToday > 0) {
            return $this->result(
                OperationsHealthStatus::Warning,
                $failuresToday === 1
                    ? '1 failure recorded today.'
                    : "{$failuresToday} failures recorded today.",
            );
        }

        if ($lastSuccessAt === null) {
            return $this->result(
                OperationsHealthStatus::Warning,
                'No successful executions recorded yet.',
            );
        }

        if ($lastSuccessAt->lt(now()->subMinutes($warningSuccessAgeMinutes))) {
            return $this->result(
                OperationsHealthStatus::Warning,
                'Last successful execution is older than expected.',
            );
        }

        return $this->result(
            OperationsHealthStatus::Healthy,
            'No failures today and recent successful execution.',
        );
    }

    private function isSchedulerStalled(
        ?Carbon $lastExecutionAt,
        int $stallMinutes,
        int $pendingCount,
        ?Carbon $oldestPendingStartedAt,
    ): bool {
        if ($lastExecutionAt === null) {
            return false;
        }

        if ($lastExecutionAt->lt(now()->subMinutes($stallMinutes))) {
            return true;
        }

        if ($pendingCount > 0 && $oldestPendingStartedAt !== null) {
            return $oldestPendingStartedAt->lt(now()->subHour());
        }

        return false;
    }

    /**
     * @return array{status: OperationsHealthStatus, label: string, badge_class: string, detail: string}
     */
    private function result(OperationsHealthStatus $status, string $detail): array
    {
        return [
            'status' => $status,
            'label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'detail' => $detail,
        ];
    }
}
