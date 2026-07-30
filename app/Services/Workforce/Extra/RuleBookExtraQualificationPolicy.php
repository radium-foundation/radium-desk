<?php

namespace App\Services\Workforce\Extra;

use App\Contracts\Workforce\CalendarPolicy;
use App\Contracts\Workforce\ExtraQualificationPolicy;
use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Extra\ExtraQualificationDecision;
use App\Enums\AttendanceDayStatus;
use App\Enums\ExtraQualificationContext;
use App\Enums\ExtraQualificationReason;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;

/**
 * Rule Book §3 / §5 / §9 — EX qualification without mutating Attendance.
 */
class RuleBookExtraQualificationPolicy implements ExtraQualificationPolicy
{
    public function __construct(
        private readonly CalendarPolicy $calendarPolicy,
    ) {}

    public function decide(
        User $user,
        Carbon $workDate,
        ?WorkforceAttendanceDay $attendance,
        ContributionEvaluation $contribution,
        bool $engineEnabled,
    ): ExtraQualificationDecision {
        $context = $this->resolveContext($user, $workDate, $attendance);

        if (! $engineEnabled) {
            $mirrorsExtra = $attendance?->status === AttendanceDayStatus::Extra;

            return new ExtraQualificationDecision(
                userId: (int) $user->id,
                workDate: $workDate->copy(),
                qualified: $mirrorsExtra,
                reason: ExtraQualificationReason::FeatureDisabled,
                ruleUsed: 'disabled.mirror_attendance_extra',
                context: $context,
                contributionVerdict: $contribution->verdict,
                attendanceState: $attendance?->status,
                engineEnabled: false,
                proposedOutcome: $mirrorsExtra ? 'EX' : $this->outcomeForContext($context, qualified: false),
            );
        }

        if ($attendance === null) {
            return new ExtraQualificationDecision(
                userId: (int) $user->id,
                workDate: $workDate->copy(),
                qualified: false,
                reason: ExtraQualificationReason::NoAttendanceDay,
                ruleUsed: 'attendance.required',
                context: $context,
                contributionVerdict: $contribution->verdict,
                attendanceState: null,
                engineEnabled: true,
                proposedOutcome: null,
            );
        }

        return match ($context) {
            ExtraQualificationContext::Leave => $this->decision(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                qualified: false,
                reason: ExtraQualificationReason::LeaveNeverExtra,
                ruleUsed: 'leave.never_extra',
                context: $context,
                proposedOutcome: 'Leave',
            ),
            ExtraQualificationContext::WorkingDay => $this->decision(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                qualified: false,
                reason: ExtraQualificationReason::WorkingDayNeverExtra,
                ruleUsed: 'working_day.present_unchanged',
                context: $context,
                proposedOutcome: 'WorkingDay',
            ),
            ExtraQualificationContext::WeeklyOff => $this->decideOffDay(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                context: ExtraQualificationContext::WeeklyOff,
                noWorkReason: ExtraQualificationReason::WeeklyOffNoWork,
                insufficientReason: ExtraQualificationReason::WeeklyOffInsufficientContribution,
                qualifiedReason: ExtraQualificationReason::WeeklyOffQualified,
                keepOutcome: 'WO',
                rulePrefix: 'weekly_off',
            ),
            ExtraQualificationContext::Holiday => $this->decideOffDay(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                context: ExtraQualificationContext::Holiday,
                noWorkReason: ExtraQualificationReason::HolidayNoWork,
                insufficientReason: ExtraQualificationReason::HolidayInsufficientContribution,
                qualifiedReason: ExtraQualificationReason::HolidayQualified,
                keepOutcome: 'Holiday',
                rulePrefix: 'holiday',
            ),
            ExtraQualificationContext::Unknown => $this->decision(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                qualified: false,
                reason: ExtraQualificationReason::NoAttendanceDay,
                ruleUsed: 'context.unknown',
                context: $context,
                proposedOutcome: null,
            ),
        };
    }

    private function decideOffDay(
        User $user,
        Carbon $workDate,
        WorkforceAttendanceDay $attendance,
        ContributionEvaluation $contribution,
        ExtraQualificationContext $context,
        ExtraQualificationReason $noWorkReason,
        ExtraQualificationReason $insufficientReason,
        ExtraQualificationReason $qualifiedReason,
        string $keepOutcome,
        string $rulePrefix,
    ): ExtraQualificationDecision {
        $hasWorkEvidence = $this->hasWorkEvidence($attendance);

        if (! $hasWorkEvidence) {
            return $this->decision(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                qualified: false,
                reason: $noWorkReason,
                ruleUsed: "{$rulePrefix}.no_work",
                context: $context,
                proposedOutcome: $keepOutcome,
            );
        }

        if ($contribution->isQualified()) {
            return $this->decision(
                user: $user,
                workDate: $workDate,
                attendance: $attendance,
                contribution: $contribution,
                qualified: true,
                reason: $qualifiedReason,
                ruleUsed: "{$rulePrefix}.qualified_contribution",
                context: $context,
                proposedOutcome: 'EX',
            );
        }

        return $this->decision(
            user: $user,
            workDate: $workDate,
            attendance: $attendance,
            contribution: $contribution,
            qualified: false,
            reason: $insufficientReason,
            ruleUsed: "{$rulePrefix}.insufficient_contribution",
            context: $context,
            proposedOutcome: $keepOutcome,
        );
    }

    private function decision(
        User $user,
        Carbon $workDate,
        WorkforceAttendanceDay $attendance,
        ContributionEvaluation $contribution,
        bool $qualified,
        ExtraQualificationReason $reason,
        string $ruleUsed,
        ExtraQualificationContext $context,
        ?string $proposedOutcome,
    ): ExtraQualificationDecision {
        return new ExtraQualificationDecision(
            userId: (int) $user->id,
            workDate: $workDate->copy(),
            qualified: $qualified,
            reason: $reason,
            ruleUsed: $ruleUsed,
            context: $context,
            contributionVerdict: $contribution->verdict,
            attendanceState: $attendance->status,
            engineEnabled: true,
            proposedOutcome: $proposedOutcome,
        );
    }

    private function resolveContext(
        User $user,
        Carbon $workDate,
        ?WorkforceAttendanceDay $attendance,
    ): ExtraQualificationContext {
        if ($attendance !== null) {
            if ($attendance->is_on_leave || $attendance->status === AttendanceDayStatus::OnLeave) {
                return ExtraQualificationContext::Leave;
            }

            if ($attendance->is_company_holiday) {
                return ExtraQualificationContext::Holiday;
            }

            if ($attendance->is_working_day) {
                return ExtraQualificationContext::WorkingDay;
            }

            return ExtraQualificationContext::WeeklyOff;
        }

        if ($this->calendarPolicy->hasApprovedLeave($user, $workDate)) {
            return ExtraQualificationContext::Leave;
        }

        if ($this->calendarPolicy->isCompanyHoliday($workDate)) {
            return ExtraQualificationContext::Holiday;
        }

        $schedule = $this->calendarPolicy->scheduleFor($user);
        if ($schedule === null) {
            return ExtraQualificationContext::Unknown;
        }

        if ($this->calendarPolicy->isWorkingDay($schedule, $workDate)) {
            return ExtraQualificationContext::WorkingDay;
        }

        return ExtraQualificationContext::WeeklyOff;
    }

    private function hasWorkEvidence(WorkforceAttendanceDay $attendance): bool
    {
        if ((int) $attendance->session_count > 0) {
            return true;
        }

        if ($attendance->first_login_at !== null) {
            return true;
        }

        return in_array($attendance->status, [
            AttendanceDayStatus::Extra,
            AttendanceDayStatus::Active,
            AttendanceDayStatus::Away,
            AttendanceDayStatus::Completed,
            AttendanceDayStatus::OnTime,
            AttendanceDayStatus::Late,
        ], true);
    }

    private function outcomeForContext(ExtraQualificationContext $context, bool $qualified): ?string
    {
        if ($qualified) {
            return 'EX';
        }

        return match ($context) {
            ExtraQualificationContext::WeeklyOff => 'WO',
            ExtraQualificationContext::Holiday => 'Holiday',
            ExtraQualificationContext::Leave => 'Leave',
            ExtraQualificationContext::WorkingDay => 'WorkingDay',
            ExtraQualificationContext::Unknown => null,
        };
    }
}
