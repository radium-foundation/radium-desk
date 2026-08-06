<?php

namespace App\Enums;

enum ShortAttendanceReviewDecision: string
{
    case ApproveFullDay = 'approve_full_day';
    case ApproveHalfDay = 'approve_half_day';
    case KeepShortAttendance = 'keep_short_attendance';
    case MarkLeave = 'mark_leave';

    public function label(): string
    {
        return match ($this) {
            self::ApproveFullDay => 'Approve Full Day',
            self::ApproveHalfDay => 'Approve Half Day',
            self::KeepShortAttendance => 'Keep Short Attendance',
            self::MarkLeave => 'Mark Leave',
        };
    }

    /**
     * Final matrix/payroll kind after HR decision.
     * Register status remains short_attendance (Phase 1 unchanged).
     */
    public function finalMatrixKind(): AttendanceMatrixCellKind
    {
        return match ($this) {
            self::ApproveFullDay => AttendanceMatrixCellKind::Present,
            self::ApproveHalfDay => AttendanceMatrixCellKind::HalfDay,
            self::KeepShortAttendance => AttendanceMatrixCellKind::ShortAttendance,
            self::MarkLeave => AttendanceMatrixCellKind::Leave,
        };
    }

    public function newStatusValue(): string
    {
        return $this->finalMatrixKind()->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
