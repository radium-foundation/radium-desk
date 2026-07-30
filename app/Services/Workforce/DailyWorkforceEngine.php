<?php

namespace App\Services\Workforce;

use App\Contracts\Workforce\CalendarPolicy;
use App\Data\Workforce\AttendanceMatrixReport;
use App\Data\Workforce\WorkforceDay;
use App\Data\Workforce\WorkforceMonthlyLedger;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\AttendanceRegisterService;
use Illuminate\Support\Carbon;

/**
 * Daily Workforce Engine — preferred façade for Workforce Management reads.
 *
 * Behaviour-preserving pass-through to AttendanceRegisterService and
 * MonthlyAttendanceMatrixService. Attendance remains the only SoT;
 * no new calculations, writers, or schema.
 */
class DailyWorkforceEngine
{
    public function __construct(
        private readonly AttendanceRegisterService $attendanceRegister,
        private readonly MonthlyAttendanceMatrixService $monthlyAttendanceMatrix,
        private readonly CalendarPolicy $calendarPolicy,
    ) {}

    /**
     * Readonly domain view over the persisted attendance day (if any).
     */
    public function day(User $user, Carbon $date): ?WorkforceDay
    {
        $attendance = $this->attendanceRegister->findDay($user, $date);

        return $attendance !== null
            ? WorkforceDay::fromAttendance($attendance)
            : null;
    }

    /**
     * Team monthly attendance matrix — pass-through to MonthlyAttendanceMatrixService::build.
     */
    public function matrix(?Carbon $month = null, ?Carbon $at = null): AttendanceMatrixReport
    {
        return $this->monthlyAttendanceMatrix->build($month, $at);
    }

    /**
     * Readonly monthly ledger via existing matrix aggregation for one user.
     */
    public function month(User $user, Carbon $month, ?Carbon $at = null): WorkforceMonthlyLedger
    {
        $monthStart = $month->copy()->startOfMonth();
        $memberRow = $this->monthlyAttendanceMatrix->buildForUser(
            user: $user,
            month: $monthStart,
            at: $at,
        );

        return WorkforceMonthlyLedger::fromMemberRow($monthStart, $memberRow);
    }

    public function refreshDay(
        User $user,
        ?Carbon $workDate = null,
        ?Carbon $referenceAt = null,
        bool $allowPreShiftSkip = true,
    ): ?WorkforceAttendanceDay {
        return $this->attendanceRegister->refreshDay(
            user: $user,
            workDate: $workDate,
            referenceAt: $referenceAt,
            allowPreShiftSkip: $allowPreShiftSkip,
        );
    }

    /**
     * Pass-through to AttendanceRegisterService::refreshDateRange.
     */
    public function refreshRange(
        User $user,
        Carbon $startDate,
        Carbon $endDate,
        ?Carbon $referenceAt = null,
    ): int {
        return $this->attendanceRegister->refreshDateRange(
            user: $user,
            startDate: $startDate,
            endDate: $endDate,
            referenceAt: $referenceAt,
        );
    }

    /**
     * Active CalendarPolicy port (adapter over WorkCalendarService).
     */
    public function calendar(): CalendarPolicy
    {
        return $this->calendarPolicy;
    }
}
