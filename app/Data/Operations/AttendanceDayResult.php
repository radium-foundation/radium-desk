<?php

namespace App\Data\Operations;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkCalendarDayStatus;
use Illuminate\Support\Carbon;

readonly class AttendanceDayResult
{
    public function __construct(
        public int $userId,
        public Carbon $workDate,
        public AttendanceDayStatus $status,
        public WorkCalendarDayStatus $calendarStatus,
        public bool $isWorkingDay,
        public bool $isCompanyHoliday,
        public bool $isOnLeave,
        public bool $hasSchedule,
        public ?Carbon $firstLoginAt,
        public ?Carbon $lastLogoutAt,
        public ?bool $onTimeLogin,
        public ?int $minutesLate,
        public int $sessionCount,
        public int $sessionDurationSeconds,
        public int $activeDurationSeconds,
        public int $idleDurationSeconds,
        public int $lunchDurationSeconds,
        public int $breakDurationSeconds,
        public int $extraIdleDurationSeconds,
        public int $overtimeSeconds,
        public int $awayTimeoutCount,
        public int $manualLogoutCount,
        public ?int $expectedWorkingMinutes,
        public ?Carbon $finalizedAt,
        public Carbon $computedAt,
        public int $sourceVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function persistenceAttributes(): array
    {
        return [
            'status' => $this->status->value,
            'calendar_status' => $this->calendarStatus->value,
            'is_working_day' => $this->isWorkingDay,
            'is_company_holiday' => $this->isCompanyHoliday,
            'is_on_leave' => $this->isOnLeave,
            'has_schedule' => $this->hasSchedule,
            'first_login_at' => $this->firstLoginAt,
            'last_logout_at' => $this->lastLogoutAt,
            'on_time_login' => $this->onTimeLogin,
            'minutes_late' => $this->minutesLate,
            'session_count' => $this->sessionCount,
            'session_duration_seconds' => $this->sessionDurationSeconds,
            'active_duration_seconds' => $this->activeDurationSeconds,
            'idle_duration_seconds' => $this->idleDurationSeconds,
            'lunch_duration_seconds' => $this->lunchDurationSeconds,
            'break_duration_seconds' => $this->breakDurationSeconds,
            'extra_idle_duration_seconds' => $this->extraIdleDurationSeconds,
            'overtime_seconds' => $this->overtimeSeconds,
            'away_timeout_count' => $this->awayTimeoutCount,
            'manual_logout_count' => $this->manualLogoutCount,
            'expected_working_minutes' => $this->expectedWorkingMinutes,
            'finalized_at' => $this->finalizedAt,
            'computed_at' => $this->computedAt,
            'source_version' => $this->sourceVersion,
        ];
    }
}
