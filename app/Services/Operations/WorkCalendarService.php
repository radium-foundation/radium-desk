<?php

namespace App\Services\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

class WorkCalendarService
{
    /**
     * @return list<int>
     */
    public function defaultWeeklyOffDays(): array
    {
        return config('workforce_calendar.default_weekly_off_days', [Carbon::SUNDAY]);
    }

    public function scheduleFor(User $user): ?TeamMemberWorkSchedule
    {
        if ($user->relationLoaded('workSchedule')) {
            return $user->workSchedule;
        }

        return $user->workSchedule()->first();
    }

    public function isCompanyHoliday(?Carbon $at = null): bool
    {
        $at ??= now();

        return CompanyHoliday::query()
            ->whereDate('holiday_date', $at->toDateString())
            ->exists();
    }

    public function hasApprovedLeave(User $user, ?Carbon $at = null): bool
    {
        $at ??= now();
        $date = $at->copy()->startOfDay();

        return LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    public function isEligibleForAssignment(User $user, ?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->isCompanyHoliday($at)) {
            return false;
        }

        if ($this->hasApprovedLeave($user, $at)) {
            return false;
        }

        $schedule = $this->scheduleFor($user);

        if ($schedule === null) {
            return true;
        }

        if (! $this->isWorkingDay($schedule, $at)) {
            return false;
        }

        return $this->isWithinWorkingHours($schedule, $at);
    }

    public function isOnScheduledShift(User $user, ?Carbon $at = null): bool
    {
        $at ??= now();

        if ($this->isCompanyHoliday($at) || $this->hasApprovedLeave($user, $at)) {
            return false;
        }

        $schedule = $this->scheduleFor($user);

        if ($schedule === null || ! $this->isWorkingDay($schedule, $at)) {
            return false;
        }

        $start = $this->timeOnDate($schedule->work_start_time, $at);
        $end = $this->timeOnDate($schedule->work_end_time, $at);

        return $at->gte($start) && $at->lt($end);
    }

    public function isWorkingDay(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        $weeklyOff = $schedule->weekly_off_days ?? $this->defaultWeeklyOffDays();

        return ! in_array($at->dayOfWeek, $weeklyOff, true);
    }

    public function isWithinWorkingHours(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        $start = $this->timeOnDate($schedule->work_start_time, $at);
        $end = $this->timeOnDate($schedule->work_end_time, $at);

        if ($at->lt($start) || $at->gte($end)) {
            return false;
        }

        if ($this->isDuringLunch($schedule, $at)) {
            return false;
        }

        return true;
    }

    public function isDuringLunch(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        if ($schedule->lunch_start_time === null || $schedule->lunch_end_time === null) {
            return false;
        }

        $lunchStart = $this->timeOnDate($schedule->lunch_start_time, $at);
        $lunchEnd = $this->timeOnDate($schedule->lunch_end_time, $at);

        return $at->gte($lunchStart) && $at->lt($lunchEnd);
    }

    public function expectedWorkingMinutes(TeamMemberWorkSchedule $schedule): int
    {
        $workMinutes = $this->minutesBetweenTimes(
            $schedule->work_start_time,
            $schedule->work_end_time,
        );

        $lunchMinutes = 0;

        if ($schedule->lunch_start_time !== null && $schedule->lunch_end_time !== null) {
            $lunchMinutes = $this->minutesBetweenTimes(
                $schedule->lunch_start_time,
                $schedule->lunch_end_time,
            );
        }

        $breakMinutes = max(0, (int) $schedule->short_break_count) * max(0, (int) $schedule->short_break_minutes);

        return max(0, $workMinutes - $lunchMinutes - $breakMinutes);
    }

    public function expectedWorkStartAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon
    {
        return $this->timeOnDate($schedule->work_start_time, $date->copy()->startOfDay());
    }

    public function expectedWorkEndAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon
    {
        return $this->timeOnDate($schedule->work_end_time, $date->copy()->startOfDay());
    }

    public function isLateLogin(User $user, Carbon $loginAt): bool
    {
        $schedule = $this->scheduleFor($user);

        if ($schedule === null || ! $this->isWorkingDay($schedule, $loginAt)) {
            return false;
        }

        if ($this->hasApprovedLeave($user, $loginAt) || $this->isCompanyHoliday($loginAt)) {
            return false;
        }

        return $loginAt->gt($this->expectedWorkStartAt($schedule, $loginAt));
    }

    /**
     * @return array{
     *     expected_start: string|null,
     *     expected_end: string|null,
     *     actual_login: string,
     *     minutes_late: int|null,
     *     is_late: bool,
     *     expected_working_minutes: int|null
     * }
     */
    public function compareLoginToSchedule(User $user, Carbon $loginAt): array
    {
        $schedule = $this->scheduleFor($user);

        if ($schedule === null) {
            return [
                'expected_start' => null,
                'expected_end' => null,
                'actual_login' => $loginAt->toIso8601String(),
                'minutes_late' => null,
                'is_late' => false,
                'expected_working_minutes' => null,
            ];
        }

        $expectedStart = $this->expectedWorkStartAt($schedule, $loginAt);
        $expectedEnd = $this->expectedWorkEndAt($schedule, $loginAt);
        $minutesLate = max(0, (int) $expectedStart->diffInMinutes($loginAt, false));

        return [
            'expected_start' => $expectedStart->toIso8601String(),
            'expected_end' => $expectedEnd->toIso8601String(),
            'actual_login' => $loginAt->toIso8601String(),
            'minutes_late' => $minutesLate > 0 ? $minutesLate : 0,
            'is_late' => $this->isLateLogin($user, $loginAt),
            'expected_working_minutes' => $this->expectedWorkingMinutes($schedule),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function todayStatusFor(User $user, ?Carbon $at = null): array
    {
        $at ??= now();
        $schedule = $this->scheduleFor($user);

        if ($this->isCompanyHoliday($at)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::Holiday, $schedule, $at);
        }

        if ($this->hasApprovedLeave($user, $at)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::LeaveApproved, $schedule, $at);
        }

        if ($schedule === null) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::NoSchedule, null, $at);
        }

        if (! $this->isWorkingDay($schedule, $at)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::WeeklyOff, $schedule, $at);
        }

        $start = $this->expectedWorkStartAt($schedule, $at);

        if ($at->lt($start)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::StartsLater, $schedule, $at);
        }

        if ($this->isDuringLunch($schedule, $at)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::Lunch, $schedule, $at);
        }

        if (! $this->isWithinWorkingHours($schedule, $at)) {
            return $this->buildStatusSnapshot(WorkCalendarDayStatus::OutsideHours, $schedule, $at);
        }

        return $this->buildStatusSnapshot(WorkCalendarDayStatus::Working, $schedule, $at);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusSnapshot(
        WorkCalendarDayStatus $status,
        ?TeamMemberWorkSchedule $schedule,
        Carbon $at,
    ): array {
        return [
            'status' => $status->value,
            'label' => $status->label(),
            'indicator' => $status->indicator(),
            'badge_class' => $status->badgeClass(),
            'work_hours' => $schedule !== null
                ? $this->formatTimeRange($schedule->work_start_time, $schedule->work_end_time)
                : null,
            'lunch_time' => $schedule !== null && $schedule->lunch_start_time !== null
                ? $this->formatTime($schedule->lunch_start_time)
                : null,
            'expected_working_minutes' => $schedule !== null ? $this->expectedWorkingMinutes($schedule) : null,
            'weekly_off_days' => $schedule?->weekly_off_days ?? $this->defaultWeeklyOffDays(),
            'checked_at' => $at->toIso8601String(),
        ];
    }

    private function timeOnDate(mixed $time, Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->normalizeTimeString($time));
    }

    private function minutesBetweenTimes(mixed $start, mixed $end): int
    {
        $startAt = Carbon::today()->setTimeFromTimeString($this->normalizeTimeString($start));
        $endAt = Carbon::today()->setTimeFromTimeString($this->normalizeTimeString($end));

        return max(0, (int) $startAt->diffInMinutes($endAt));
    }

    private function normalizeTimeString(mixed $time): string
    {
        if ($time instanceof Carbon) {
            return $time->format('H:i:s');
        }

        $value = (string) $time;

        if (strlen($value) === 5) {
            return $value.':00';
        }

        return $value;
    }

    private function formatTime(mixed $time): string
    {
        return Carbon::today()->setTimeFromTimeString($this->normalizeTimeString($time))->format('H:i');
    }

    private function formatTimeRange(mixed $start, mixed $end): string
    {
        return $this->formatTime($start).' - '.$this->formatTime($end);
    }
}
