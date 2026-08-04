<?php

namespace App\Services\Operations;

use App\Enums\OperationsHealthStatus;
use Illuminate\Support\Carbon;

class AutomationHealthStatusCalculator
{
    /**
     * Operational health uses open/critical failures only.
     * Historical (terminal) ledger failures do not degrade status.
     *
     * @return array{status: OperationsHealthStatus, label: string, badge_class: string, detail: string}
     */
    public function calculate(
        ?Carbon $lastSuccessAt,
        ?Carbon $lastExecutionAt,
        int $failuresToday,
        int $pendingCount,
        ?Carbon $oldestPendingStartedAt,
        int $openFailuresToday = 0,
        int $criticalFailuresToday = 0,
        int $historicalFailuresToday = 0,
    ): array {
        $stallMinutes = max(1, (int) config('operations.automation_health.stall_threshold_minutes', 120));
        $warningSuccessAgeMinutes = max(1, (int) config('operations.automation_health.warning_success_age_minutes', 60));

        if ($this->isSchedulerStalled($lastExecutionAt, $stallMinutes, $pendingCount, $oldestPendingStartedAt)) {
            return $this->result(
                OperationsHealthStatus::Failed,
                'Scheduler appears stalled — no recent automation activity.',
            );
        }

        if ($criticalFailuresToday > 0) {
            return $this->result(
                OperationsHealthStatus::Failed,
                $criticalFailuresToday === 1
                    ? '1 critical automation failure requires attention.'
                    : "{$criticalFailuresToday} critical automation failures require attention.",
            );
        }

        if ($openFailuresToday > 0) {
            return $this->result(
                OperationsHealthStatus::Warning,
                $openFailuresToday === 1
                    ? '1 open automation failure requires attention.'
                    : "{$openFailuresToday} open automation failures require attention.",
            );
        }

        if ($lastSuccessAt === null) {
            return $this->result(
                OperationsHealthStatus::Warning,
                'No successful executions recorded yet.',
            );
        }

        if ($lastSuccessAt->lt(now()->subMinutes($warningSuccessAgeMinutes))) {
            $detail = 'Last successful execution is older than expected.';
            if ($historicalFailuresToday > 0) {
                $detail .= " {$historicalFailuresToday} historical failure(s) today (audit only).";
            }

            return $this->result(
                OperationsHealthStatus::Warning,
                $detail,
            );
        }

        $detail = 'No open failures and recent successful execution.';
        if ($historicalFailuresToday > 0) {
            $detail = "Healthy — {$historicalFailuresToday} historical failure(s) today are audit-only.";
        } elseif ($failuresToday > 0) {
            $detail = 'No open failures and recent successful execution.';
        }

        return $this->result(
            OperationsHealthStatus::Healthy,
            $detail,
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

        // Ignore ancient orphan pending rows — they are not a live scheduler stall.
        $pendingHorizonHours = max(1, (int) config('operations.automation_health.pending_stall_horizon_hours', 24));

        if (
            $pendingCount > 0
            && $oldestPendingStartedAt !== null
            && $oldestPendingStartedAt->gte(now()->subHours($pendingHorizonHours))
            && $oldestPendingStartedAt->lt(now()->subHour())
        ) {
            return true;
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
