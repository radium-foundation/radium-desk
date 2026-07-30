<?php

namespace App\Data\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;

/**
 * Readonly domain view over a workforce_attendance_days row.
 * Does not duplicate persisted fields; wraps the attendance record.
 */
readonly class WorkforceDay
{
    public function __construct(
        private WorkforceAttendanceDay $attendance,
    ) {}

    public static function fromAttendance(WorkforceAttendanceDay $attendance): self
    {
        return new self($attendance);
    }

    public function attendance(): WorkforceAttendanceDay
    {
        return $this->attendance;
    }

    public function userId(): int
    {
        return (int) $this->attendance->user_id;
    }

    public function workDate(): Carbon
    {
        return $this->attendance->work_date->copy()->startOfDay();
    }

    public function status(): AttendanceDayStatus
    {
        return $this->attendance->status;
    }

    public function calendarStatus(): ?WorkCalendarDayStatus
    {
        return $this->attendance->calendar_status;
    }

    public function isWorkingDay(): bool
    {
        return (bool) $this->attendance->is_working_day;
    }

    public function isCompanyHoliday(): bool
    {
        return (bool) $this->attendance->is_company_holiday;
    }

    public function isOnLeave(): bool
    {
        return (bool) $this->attendance->is_on_leave;
    }

    public function hasSchedule(): bool
    {
        return (bool) $this->attendance->has_schedule;
    }

    public function onTimeLogin(): ?bool
    {
        return $this->attendance->on_time_login;
    }

    public function minutesLate(): ?int
    {
        return $this->attendance->minutes_late;
    }

    public function sessionCount(): int
    {
        return (int) $this->attendance->session_count;
    }

    public function activeDurationSeconds(): int
    {
        return (int) $this->attendance->active_duration_seconds;
    }

    public function overtimeSeconds(): int
    {
        return (int) $this->attendance->overtime_seconds;
    }

    public function firstLoginAt(): ?Carbon
    {
        return $this->attendance->first_login_at?->copy();
    }

    public function lastLogoutAt(): ?Carbon
    {
        return $this->attendance->last_logout_at?->copy();
    }

    public function finalizedAt(): ?Carbon
    {
        return $this->attendance->finalized_at?->copy();
    }

    public function isFinalized(): bool
    {
        return $this->attendance->finalized_at !== null;
    }
}
