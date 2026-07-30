<?php

namespace App\Services\Workforce;

use App\Data\Workforce\AttendanceMatrixCell;
use App\Data\Workforce\AttendanceMatrixDayHeader;
use App\Data\Workforce\AttendanceMatrixMemberRow;
use App\Data\Workforce\AttendanceMatrixMemberSummary;
use App\Data\Workforce\AttendanceMatrixReport;
use App\Data\Workforce\AttendanceMatrixTeamSummary;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveRequestStatus;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\OperationsRoleService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MonthlyAttendanceMatrixService
{
    public function __construct(
        private readonly AttendanceRegisterService $attendanceRegister,
        private readonly OperationsRoleService $roleService,
        private readonly AttendanceMatrixCellMapper $cellMapper,
    ) {}

    public function build(?Carbon $month = null, ?Carbon $at = null): AttendanceMatrixReport
    {
        $at ??= now();
        $month = ($month ?? $at->copy())->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth()->startOfDay();
        $today = $at->copy()->startOfDay();
        $resolveThrough = $monthEnd->lt($today) ? $monthEnd->copy() : $today->copy();

        $users = $this->trackedUsers();
        $holidaysByDate = $this->holidaysForMonth($month, $monthEnd);
        $leaveReasonsByUserDate = $this->approvedLeaveReasons($users, $month, $monthEnd);

        $days = $this->buildDayHeaders($month, $monthEnd, $today, $holidaysByDate);
        $members = [];

        $teamPresent = 0;
        $teamAbsent = 0;
        $teamLeave = 0;
        $teamLate = 0;
        $teamHoliday = 0;

        foreach ($users as $user) {
            $dayRows = $this->attendanceRegister->resolveDaysForRange(
                user: $user,
                startDate: $month->copy(),
                endDate: $resolveThrough->copy(),
                referenceAt: $at,
                allowPreShiftSkip: false,
            )->keyBy(fn (WorkforceAttendanceDay $day): string => $day->work_date->toDateString());

            $cells = [];
            $presentDays = 0;
            $absentDays = 0;
            $leaveDays = 0;
            $lateDays = 0;
            $holidayDays = 0;
            $weeklyOffDays = 0;
            $extraDays = 0;
            $activeSeconds = 0;
            $overtimeSeconds = 0;

            foreach ($days as $header) {
                $dateKey = $header->date->toDateString();
                $day = $dayRows->get($dateKey);
                $context = [
                    'holiday_name' => $holidaysByDate->get($dateKey)?->name,
                    'leave_reason' => $leaveReasonsByUserDate[$user->id][$dateKey] ?? null,
                ];

                $kind = $this->cellMapper->kindFor($day, $header->date, $today);
                $cell = new AttendanceMatrixCell(
                    userId: $user->id,
                    workDate: $dateKey,
                    kind: $kind,
                    shortLabel: $kind->shortLabel(),
                    tone: $kind->tone(),
                    tooltip: $this->cellMapper->tooltipFor($kind, $day, $header->date, $context),
                    interactive: $kind->isInteractive(),
                    disabled: $kind === AttendanceMatrixCellKind::Future,
                    attendanceStatus: $day?->status,
                    drawerPayload: $this->cellMapper->drawerPayload(
                        userId: $user->id,
                        employeeName: (string) $user->name,
                        workDate: $header->date,
                        kind: $kind,
                        day: $day,
                        context: $context,
                    ),
                );

                $cells[$dateKey] = $cell;

                match ($kind) {
                    AttendanceMatrixCellKind::Present => $presentDays++,
                    AttendanceMatrixCellKind::Absent => $absentDays++,
                    AttendanceMatrixCellKind::Leave => $leaveDays++,
                    AttendanceMatrixCellKind::Late => $lateDays++,
                    AttendanceMatrixCellKind::Holiday => $holidayDays++,
                    AttendanceMatrixCellKind::WeeklyOff => $weeklyOffDays++,
                    AttendanceMatrixCellKind::Extra => $extraDays++,
                    default => null,
                };

                if ($day !== null) {
                    $activeSeconds += (int) $day->active_duration_seconds;
                    $overtimeSeconds += (int) $day->overtime_seconds;
                }
            }

            $teamPresent += $presentDays;
            $teamAbsent += $absentDays;
            $teamLeave += $leaveDays;
            $teamLate += $lateDays;
            $teamHoliday += $holidayDays;

            $members[] = new AttendanceMatrixMemberRow(
                userId: $user->id,
                name: (string) $user->name,
                roleLabel: $this->roleService->displayLabel($user->roles->first()?->name),
                cells: $cells,
                summary: new AttendanceMatrixMemberSummary(
                    presentDays: $presentDays,
                    absentDays: $absentDays,
                    leaveDays: $leaveDays,
                    lateDays: $lateDays,
                    holidayDays: $holidayDays,
                    weeklyOffDays: $weeklyOffDays,
                    extraDays: $extraDays,
                    activeDurationSeconds: $activeSeconds,
                    overtimeSeconds: $overtimeSeconds,
                    hoursLabel: $this->cellMapper->formatDuration($activeSeconds),
                    overtimeLabel: $this->cellMapper->formatDuration($overtimeSeconds),
                ),
            );
        }

        return new AttendanceMatrixReport(
            month: $month->copy(),
            monthLabel: $month->format('F Y'),
            monthValue: $month->format('Y-m'),
            days: $days,
            members: $members,
            teamSummary: new AttendanceMatrixTeamSummary(
                present: $teamPresent,
                absent: $teamAbsent,
                leave: $teamLeave,
                late: $teamLate,
                holiday: $teamHoliday,
            ),
            generatedAt: $at->copy(),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function trackedUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->values();
    }

    /**
     * @return Collection<string, CompanyHoliday>
     */
    private function holidaysForMonth(Carbon $start, Carbon $end): Collection
    {
        return CompanyHoliday::query()
            ->whereDate('holiday_date', '>=', $start->toDateString())
            ->whereDate('holiday_date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (CompanyHoliday $holiday): string => $holiday->holiday_date->toDateString());
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, string>>
     */
    private function approvedLeaveReasons(Collection $users, Carbon $start, Carbon $end): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $requests = LeaveRequest::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get();

        $reasons = [];

        foreach ($requests as $request) {
            $cursor = $request->start_date->copy()->startOfDay();
            $requestEnd = $request->end_date->copy()->startOfDay();

            while ($cursor->lte($requestEnd)) {
                if ($cursor->gte($start) && $cursor->lte($end)) {
                    $reasons[$request->user_id][$cursor->toDateString()] = (string) $request->reason;
                }
                $cursor->addDay();
            }
        }

        return $reasons;
    }

    /**
     * @param  Collection<string, CompanyHoliday>  $holidaysByDate
     * @return list<AttendanceMatrixDayHeader>
     */
    private function buildDayHeaders(
        Carbon $monthStart,
        Carbon $monthEnd,
        Carbon $today,
        Collection $holidaysByDate,
    ): array {
        $headers = [];
        $cursor = $monthStart->copy();

        while ($cursor->lte($monthEnd)) {
            $dateKey = $cursor->toDateString();
            $holiday = $holidaysByDate->get($dateKey);
            $dayOfWeek = $cursor->dayOfWeek;

            $headers[] = new AttendanceMatrixDayHeader(
                date: $cursor->copy(),
                dayNumber: (int) $cursor->day,
                weekdayLabel: $cursor->format('D'),
                isWeekend: in_array($dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY], true),
                isHoliday: $holiday !== null,
                isFuture: $cursor->gt($today),
                holidayName: $holiday?->name,
            );

            $cursor->addDay();
        }

        return $headers;
    }
}
