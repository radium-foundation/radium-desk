<?php

namespace App\Services\Operations\LeavePolicy;

use App\Data\Operations\LeavePolicyEvaluation;
use App\Enums\LeaveDuration;
use App\Models\User;
use Illuminate\Support\Carbon;

class LeaveAutoApprovalEvaluator
{
    public function isEnabled(): bool
    {
        return (bool) config('leave_policy.auto_approval.enabled', false);
    }

    public function balanceLedgerAvailable(): bool
    {
        return (bool) config('leave_policy.auto_approval.leave_balance_ledger_available', false);
    }

    public function blockReasonWhenDisabled(): string
    {
        return 'Auto-approval is disabled by configuration (pilot gate).';
    }

    /**
     * Structural eligibility only — entitlement/balance is enforced when a ledger exists.
     *
     * @return array{eligible: bool, reasons: list<string>, phase: ?string}
     */
    public function evaluatePhaseStructure(
        User $requester,
        LeavePolicyEvaluation $policy,
        LeaveDuration $duration,
        bool $manualException,
    ): array {
        $reasons = [];

        if ($manualException) {
            $reasons[] = 'Manual exception requests never auto-approve.';
        }

        if ($policy->requiresManualReview || $policy->sundaySandwichApplied) {
            $reasons[] = 'Sunday sandwich or manual-review flags require approver action.';
        }

        if ($policy->blocksAutoApproval) {
            $reasons[] = $policy->blockReason ?? 'Coverage or policy block.';
        }

        if ($duration === LeaveDuration::HalfDay) {
            $reasons[] = 'Half-day leave requires Management approval.';
        }

        $phase = (string) config('leave_policy.auto_approval.phase', 'pilot_1');

        if ($phase === 'pilot_2' && ! (bool) config('leave_policy.auto_approval.pilot_2.enabled', false)) {
            $reasons[] = 'Phase 2 auto-approval is not enabled after pilot review.';
        }

        $phaseConfig = $phase === 'pilot_2'
            ? config('leave_policy.auto_approval.pilot_2', [])
            : config('leave_policy.auto_approval.pilot_1', []);

        $allowedWeekdays = $phaseConfig['allowed_weekdays'] ?? [];
        $maxSpan = (int) ($phaseConfig['max_span_working_days'] ?? 1);

        $start = $policy->requestedStart->copy()->startOfDay();
        $end = $policy->requestedEnd->copy()->startOfDay();
        $spanDays = (int) $start->diffInDays($end) + 1;

        if ($spanDays > $maxSpan) {
            $reasons[] = "Span {$spanDays} day(s) exceeds phase limit ({$maxSpan}).";
        }

        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            if (! in_array((int) $cursor->dayOfWeek, $allowedWeekdays, true)) {
                $reasons[] = 'Leave includes a day outside Tue–Fri pilot window.';
                break;
            }
            if ((int) $cursor->dayOfWeek === Carbon::MONDAY) {
                $reasons[] = 'Monday leave remains manual in all phases.';
                break;
            }
            if (in_array((int) $cursor->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true)) {
                $reasons[] = 'Weekend leave remains manual.';
                break;
            }
            $cursor->addDay();
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'phase' => $phase,
        ];
    }
}
