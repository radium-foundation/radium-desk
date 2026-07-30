<?php

namespace App\Services\Workforce\Extra;

use App\Contracts\Workforce\ExtraQualificationPolicy;
use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Extra\ExtraQualificationDecision;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\WorkforceEventType;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Workforce\Contribution\ContributionEngine;
use Illuminate\Support\Carbon;

/**
 * Extra Qualification Engine — qualifies EX using Contribution + Attendance + Calendar.
 *
 * Reads only. Never mutates Attendance.
 * Feature flag: workforce.extra_qualification.enabled (default false).
 */
class ExtraQualificationEngine
{
    public function __construct(
        private readonly ExtraQualificationPolicy $extraQualificationPolicy,
        private readonly ContributionEngine $contributionEngine,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('workforce.extra_qualification.enabled', false);
    }

    public function evaluate(
        User $user,
        Carbon $date,
        ?ContributionEvaluation $contribution = null,
    ): ExtraQualificationDecision {
        $workDate = $date->copy()->startOfDay();

        $attendance = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $contribution ??= $this->contributionEngine->evaluate($user, $workDate);

        $decision = $this->extraQualificationPolicy->decide(
            user: $user,
            workDate: $workDate,
            attendance: $attendance,
            contribution: $contribution,
            engineEnabled: $this->enabled(),
        );

        if ($this->enabled() && $decision->isQualified()) {
            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: WorkforceEventType::ExtraDayEarned,
                userId: (int) $user->id,
                workDate: $workDate,
                payload: [
                    'reason' => $decision->reason->value,
                    'rule_used' => $decision->ruleUsed,
                    'context' => $decision->context->value,
                    'contribution_verdict' => $decision->contributionVerdict?->value,
                    'attendance_state' => $decision->attendanceState?->value,
                    'proposed_outcome' => $decision->proposedOutcome,
                ],
            ));
        }

        return $decision;
    }
}
