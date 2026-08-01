<?php

namespace App\Services\Workforce\Recognition;

use App\Data\Workforce\Recognition\RecognitionCandidate;
use App\Enums\AttendanceDayStatus;
use App\Enums\RecognitionDayContext;
use App\Models\CompanyHoliday;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\WorkCalendarService;
use Illuminate\Support\Carbon;

/**
 * Detects Weekly Off / Holiday person-days with activity for Work Recognition.
 * Never writes attendance.
 */
class RecognitionCandidateDetector
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function detect(User $user, Carbon $date): ?RecognitionCandidate
    {
        $workDate = $date->copy()->startOfDay();
        $attendance = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        if ($attendance !== null && in_array($attendance->status, [
            AttendanceDayStatus::OnLeave,
            AttendanceDayStatus::HalfDay,
        ], true)) {
            return null;
        }

        $dayContext = $this->resolveDayContext($user, $workDate, $attendance);

        if ($dayContext === null) {
            return null;
        }

        if (! $this->hasActivity($user, $workDate, $attendance)) {
            return null;
        }

        return new RecognitionCandidate(
            userId: (int) $user->id,
            workDate: $workDate,
            dayContext: $dayContext,
        );
    }

    private function resolveDayContext(
        User $user,
        Carbon $workDate,
        ?WorkforceAttendanceDay $attendance,
    ): ?RecognitionDayContext {
        if (CompanyHoliday::query()->whereDate('holiday_date', $workDate->toDateString())->exists()) {
            return RecognitionDayContext::CompanyHoliday;
        }

        if ($attendance?->is_company_holiday) {
            return RecognitionDayContext::CompanyHoliday;
        }

        $schedule = $this->workCalendarService->scheduleFor($user, $workDate);

        if ($schedule !== null && ! $this->workCalendarService->isWorkingDay($schedule, $workDate)) {
            return RecognitionDayContext::WeeklyOff;
        }

        // Attendance already classified Extra / ScheduledOff when schedule covered the day.
        if ($attendance?->status === AttendanceDayStatus::Extra) {
            return RecognitionDayContext::WeeklyOff;
        }

        if ($attendance?->status === AttendanceDayStatus::ScheduledOff && ! $attendance->is_company_holiday) {
            return RecognitionDayContext::WeeklyOff;
        }

        return null;
    }

    private function hasActivity(
        User $user,
        Carbon $workDate,
        ?WorkforceAttendanceDay $attendance,
    ): bool {
        if ($attendance !== null && (
            (int) $attendance->session_count > 0
            || (int) $attendance->session_duration_seconds > 0
            || (int) $attendance->active_duration_seconds > 0
        )) {
            return true;
        }

        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->exists();
    }
}
