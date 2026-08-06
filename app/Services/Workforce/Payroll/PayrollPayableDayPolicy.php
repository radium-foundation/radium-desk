<?php

namespace App\Services\Workforce\Payroll;

use App\Data\Workforce\AttendanceMatrixCell;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeavePayClass;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase 1 payable-day rules for payroll (independent of matrix payableDays).
 */
class PayrollPayableDayPolicy
{
    /**
     * @param  array<string, AttendanceMatrixCell>  $cells
     * @param  array<string, LeavePayClass>  $leavePayClassByDate  Y-m-d => pay class
     * @return array{
     *     payable_days: float,
     *     non_payable_days: float,
     *     present: int,
     *     late: int,
     *     leave: int,
     *     half_day: int,
     *     weekly_off: int,
     *     holiday: int,
     *     absent: int,
     *     extra: int
     * }
     */
    public function summarize(array $cells, array $leavePayClassByDate = []): array
    {
        $payable = 0.0;
        $nonPayable = 0.0;
        $counts = [
            'present' => 0,
            'late' => 0,
            'leave' => 0,
            'half_day' => 0,
            'weekly_off' => 0,
            'holiday' => 0,
            'absent' => 0,
            'extra' => 0,
        ];

        foreach ($cells as $dateKey => $cell) {
            $payClass = $leavePayClassByDate[$dateKey] ?? LeavePayClass::Paid;

            switch ($cell->kind) {
                case AttendanceMatrixCellKind::Present:
                    $payable += 1.0;
                    $counts['present']++;
                    break;
                case AttendanceMatrixCellKind::Late:
                    $payable += 1.0;
                    $counts['late']++;
                    break;
                case AttendanceMatrixCellKind::WeeklyOff:
                    $payable += 1.0;
                    $counts['weekly_off']++;
                    break;
                case AttendanceMatrixCellKind::Holiday:
                    $payable += 1.0;
                    $counts['holiday']++;
                    break;
                case AttendanceMatrixCellKind::Leave:
                    $counts['leave']++;
                    if ($payClass === LeavePayClass::Unpaid) {
                        $nonPayable += 1.0;
                    } else {
                        $payable += 1.0;
                    }
                    break;
                case AttendanceMatrixCellKind::HalfDay:
                    $counts['half_day']++;
                    if ($payClass === LeavePayClass::Unpaid) {
                        $nonPayable += 0.5;
                    } else {
                        $payable += 0.5;
                    }
                    break;
                case AttendanceMatrixCellKind::Absent:
                case AttendanceMatrixCellKind::ShortAttendance:
                    // Phase 1: Short Attendance is payroll-identical to Absent.
                    $nonPayable += 1.0;
                    $counts['absent']++;
                    break;
                case AttendanceMatrixCellKind::Extra:
                    $counts['extra']++;
                    break;
                default:
                    break;
            }
        }

        return [
            'payable_days' => $payable,
            'non_payable_days' => $nonPayable,
            ...$counts,
        ];
    }

    /**
     * Approved leave pay_class keyed by each covered calendar date.
     *
     * @return array<string, LeavePayClass>
     */
    public function leavePayClassByDateForMonth(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $leaves = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get(['start_date', 'end_date', 'pay_class']);

        $map = [];
        foreach ($leaves as $leave) {
            $cursor = $leave->start_date->copy()->startOfDay();
            $leaveEnd = $leave->end_date->copy()->startOfDay();
            $payClass = $leave->pay_class ?? LeavePayClass::Paid;

            while ($cursor->lte($leaveEnd)) {
                $key = $cursor->toDateString();
                if ($key >= $start && $key <= $end) {
                    $map[$key] = $payClass;
                }
                $cursor->addDay();
            }
        }

        return $map;
    }
}
