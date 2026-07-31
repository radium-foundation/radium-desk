<?php

namespace App\Support\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;

class AttendanceMatrixCellMapper
{
    /**
     * Map a register day (or lack thereof) into a presentation kind.
     * Does not invent attendance math — only classifies existing register values.
     */
    public function kindFor(
        ?WorkforceAttendanceDay $day,
        Carbon $workDate,
        Carbon $today,
    ): AttendanceMatrixCellKind {
        if ($workDate->gt($today)) {
            return AttendanceMatrixCellKind::Future;
        }

        if ($day === null) {
            return AttendanceMatrixCellKind::Empty;
        }

        return match ($day->status) {
            AttendanceDayStatus::OnLeave => AttendanceMatrixCellKind::Leave,
            AttendanceDayStatus::HalfDay => AttendanceMatrixCellKind::HalfDay,
            AttendanceDayStatus::ScheduledOff => $day->is_company_holiday
                ? AttendanceMatrixCellKind::Holiday
                : AttendanceMatrixCellKind::WeeklyOff,
            AttendanceDayStatus::Extra => AttendanceMatrixCellKind::Extra,
            AttendanceDayStatus::NotStarted => AttendanceMatrixCellKind::Absent,
            AttendanceDayStatus::Late => AttendanceMatrixCellKind::Late,
            AttendanceDayStatus::OnTime,
            AttendanceDayStatus::Active,
            AttendanceDayStatus::Away,
            AttendanceDayStatus::Completed => $day->on_time_login === false
                ? AttendanceMatrixCellKind::Late
                : AttendanceMatrixCellKind::Present,
        };
    }

    /**
     * @param  array{holiday_name?: ?string, leave_reason?: ?string}  $context
     */
    public function tooltipFor(
        AttendanceMatrixCellKind $kind,
        ?WorkforceAttendanceDay $day,
        Carbon $workDate,
        array $context = [],
    ): string {
        $parts = [
            $workDate->format('D, M j Y'),
            $kind->label(),
        ];

        if ($kind === AttendanceMatrixCellKind::Holiday && filled($context['holiday_name'] ?? null)) {
            $parts[] = (string) $context['holiday_name'];
        }

        if ($kind === AttendanceMatrixCellKind::Leave && filled($context['leave_reason'] ?? null)) {
            $parts[] = 'Reason: '.$context['leave_reason'];
        }

        if ($day === null) {
            return implode(' · ', $parts);
        }

        if ($day->first_login_at !== null) {
            $login = $day->first_login_at->format('H:i');
            $logout = $day->last_logout_at?->format('H:i') ?? 'open';
            $parts[] = "Login {$login} – {$logout}";
        }

        if ($day->on_time_login === false && $day->minutes_late !== null) {
            $parts[] = $day->minutes_late.' min late';
        }

        if ((int) $day->active_duration_seconds > 0) {
            $parts[] = 'Active '.$this->formatDuration((int) $day->active_duration_seconds);
        }

        if ((int) $day->overtime_seconds > 0) {
            $parts[] = 'OT '.$this->formatDuration((int) $day->overtime_seconds);
        }

        if ($day->status !== null) {
            $parts[] = 'Register: '.$day->status->label();
        }

        return implode(' · ', $parts);
    }

    /**
     * Payload for a future attendance drawer — no UI yet.
     *
     * @param  array{holiday_name?: ?string, leave_reason?: ?string}  $context
     * @return array<string, mixed>
     */
    public function drawerPayload(
        int $userId,
        string $employeeName,
        Carbon $workDate,
        AttendanceMatrixCellKind $kind,
        ?WorkforceAttendanceDay $day,
        array $context = [],
    ): array {
        return [
            'user_id' => $userId,
            'employee_name' => $employeeName,
            'work_date' => $workDate->toDateString(),
            'kind' => $kind->value,
            'kind_label' => $kind->label(),
            'attendance_status' => $day?->status?->value,
            'attendance_status_label' => $day?->status?->label(),
            'is_working_day' => $day?->is_working_day,
            'is_company_holiday' => $day?->is_company_holiday,
            'is_on_leave' => $day?->is_on_leave,
            'holiday_name' => $context['holiday_name'] ?? null,
            'leave_reason' => $context['leave_reason'] ?? null,
            'first_login_at' => $day?->first_login_at?->toIso8601String(),
            'last_logout_at' => $day?->last_logout_at?->toIso8601String(),
            'on_time_login' => $day?->on_time_login,
            'minutes_late' => $day?->minutes_late,
            'session_count' => $day?->session_count,
            'active_duration_seconds' => $day?->active_duration_seconds,
            'overtime_seconds' => $day?->overtime_seconds,
            'away_timeout_count' => $day?->away_timeout_count,
            'manual_logout_count' => $day?->manual_logout_count,
        ];
    }

    public function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }
}
