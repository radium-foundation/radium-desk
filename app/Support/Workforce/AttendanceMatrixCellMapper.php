<?php

namespace App\Support\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;

class AttendanceMatrixCellMapper
{
    /**
     * Minutes late for today when the register already classifies the day as Late.
     * Returns null for Present / Leave / Holiday / Weekly Off / etc.
     */
    public function lateMinutesForDisplay(?WorkforceAttendanceDay $day, Carbon $today): ?int
    {
        if ($day === null) {
            return null;
        }

        if ($this->kindFor($day, $today, $today) !== AttendanceMatrixCellKind::Late) {
            return null;
        }

        return max(0, (int) ($day->minutes_late ?? 0));
    }

    /**
     * Map a register day (or lack thereof) into a presentation kind.
     * Does not invent attendance math — only classifies existing register values.
     *
     * Phase 2: optional $shortAttendanceOverride applies HR-approved final status
     * when the register is still short_attendance (Phase 1 source of truth unchanged).
     */
    public function kindFor(
        ?WorkforceAttendanceDay $day,
        Carbon $workDate,
        Carbon $today,
        ?AttendanceMatrixCellKind $shortAttendanceOverride = null,
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
            AttendanceDayStatus::ShortAttendance => $shortAttendanceOverride
                ?? AttendanceMatrixCellKind::ShortAttendance,
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
            $this->kindLegendLabel($kind),
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

        if ($kind === AttendanceMatrixCellKind::ShortAttendance) {
            $workedMinutes = intdiv(max(0, (int) $day->active_duration_seconds), 60);
            $parts[] = $workedMinutes.' min worked';
        } elseif ((int) $day->active_duration_seconds > 0) {
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
     * Legend-aligned tooltip status line (short code + full label).
     */
    public function kindLegendLabel(AttendanceMatrixCellKind $kind): string
    {
        $short = $kind->shortLabel();

        if ($short === '—' || $short === '') {
            return $kind->label();
        }

        return $short.' · '.$kind->label();
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
            'status_reason' => $day?->status_reason,
            'final_kind' => $kind->value,
            'final_kind_label' => $kind->label(),
            'worked_minutes' => $day !== null
                ? intdiv(max(0, (int) $day->active_duration_seconds), 60)
                : null,
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
