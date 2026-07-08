<?php

namespace App\Services\Operations;

use App\Data\Operations\PerformancePeriodRange;
use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\AuditLog;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\ServiceCaseCloseException;
use App\Models\SupportAppointment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamPerformanceMetricsService
{
    public function __construct(
        private readonly PerformancePeriodService $periodService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
        private readonly PresenceEngineService $presenceEngineService,
    ) {}

    public function metricsFor(
        User $user,
        PerformancePeriod|string|null $period = null,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null,
        ?Carbon $at = null,
    ): TeamMemberPerformanceMetrics {
        $range = $this->periodService->resolve($period, $customStart, $customEnd, $at);
        $user->loadMissing(['workSchedule', 'roles']);

        $sessions = $this->sessionsFor($user, $range);
        $schedule = $this->workCalendarService->scheduleFor($user);
        $dayBreakdown = $this->dayBreakdown($user, $schedule, $range);

        return new TeamMemberPerformanceMetrics(
            userId: $user->id,
            name: $user->name,
            roleLabel: $this->roleService->displayLabel($user->roles->first()?->name),
            range: $range,
            attendance: $this->buildAttendanceMetrics($dayBreakdown),
            login: $this->buildLoginMetrics($sessions),
            presence: $this->buildPresenceMetrics($sessions, $dayBreakdown, $schedule),
            customerWork: $this->buildCustomerWorkMetrics($user, $sessions, $range),
            quality: $this->buildQualityMetrics($user, $range),
        );
    }

    /**
     * @return list<TeamMemberPerformanceMetrics>
     */
    public function teamMetrics(
        PerformancePeriod|string|null $period = null,
        ?Carbon $customStart = null,
        ?Carbon $customEnd = null,
        ?Carbon $at = null,
    ): array {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->map(fn (User $user): TeamMemberPerformanceMetrics => $this->metricsFor(
                $user,
                $period,
                $customStart,
                $customEnd,
                $at,
            ))
            ->all();
    }

    /**
     * @return Collection<int, WorkSession>
     */
    private function sessionsFor(User $user, PerformancePeriodRange $range): Collection
    {
        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $range->start->toDateString())
            ->whereDate('work_date', '<=', $range->end->toDateString())
            ->orderBy('work_date')
            ->get();
    }

    /**
     * @return list<array{
     *     date: Carbon,
     *     is_working_day: bool,
     *     is_holiday: bool,
     *     is_leave: bool,
     *     is_present: bool
     * }>
     */
    private function dayBreakdown(
        User $user,
        ?TeamMemberWorkSchedule $schedule,
        PerformancePeriodRange $range,
    ): array {
        $holidayDates = CompanyHoliday::query()
            ->whereDate('holiday_date', '>=', $range->start->toDateString())
            ->whereDate('holiday_date', '<=', $range->end->toDateString())
            ->pluck('holiday_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->all();

        $leaveRanges = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('start_date', '<=', $range->end->toDateString())
            ->whereDate('end_date', '>=', $range->start->toDateString())
            ->get(['start_date', 'end_date']);

        $presentDates = WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $range->start->toDateString())
            ->whereDate('work_date', '<=', $range->end->toDateString())
            ->pluck('work_date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();

        $days = [];
        $cursor = $range->start->copy()->startOfDay();
        $end = $range->end->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $dateString = $cursor->toDateString();
            $isHoliday = in_array($dateString, $holidayDates, true);
            $isWeeklyOff = $schedule !== null && ! $this->workCalendarService->isWorkingDay($schedule, $cursor);
            $isWorkingDay = ! $isHoliday && ! $isWeeklyOff;
            $isLeave = $leaveRanges->contains(
                fn (LeaveRequest $leave): bool => $cursor->between(
                    $leave->start_date->copy()->startOfDay(),
                    $leave->end_date->copy()->endOfDay(),
                ),
            );

            $days[] = [
                'date' => $cursor->copy(),
                'is_working_day' => $isWorkingDay,
                'is_holiday' => $isHoliday,
                'is_leave' => $isLeave && $isWorkingDay,
                'is_present' => $isWorkingDay && ! $isLeave && isset($presentDates[$dateString]),
            ];

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * @param  list<array{date: Carbon, is_working_day: bool, is_holiday: bool, is_leave: bool, is_present: bool}>  $dayBreakdown
     * @return array<string, int|string>
     */
    private function buildAttendanceMetrics(array $dayBreakdown): array
    {
        $workingDays = collect($dayBreakdown)->where('is_working_day', true)->count();
        $presentDays = collect($dayBreakdown)->where('is_present', true)->count();
        $leaveDays = collect($dayBreakdown)->where('is_leave', true)->count();

        return [
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'attendance_label' => $workingDays > 0
                ? "{$presentDays}/{$workingDays} days"
                : '—',
        ];
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @return array<string, int|float|string|null>
     */
    private function buildLoginMetrics(Collection $sessions): array
    {
        $evaluated = $sessions->filter(fn (WorkSession $session): bool => $session->on_time_login !== null);
        $onTimeCount = $evaluated->where('on_time_login', true)->count();
        $lateDays = $evaluated->where('on_time_login', false)->count();
        $evaluatedCount = $evaluated->count();

        $loginMinutes = $evaluated
            ->map(fn (WorkSession $session): int => ((int) $session->login_at?->format('H')) * 60 + (int) $session->login_at?->format('i'))
            ->filter(fn (int $minutes): bool => $minutes >= 0);

        $averageLoginTime = null;
        if ($loginMinutes->isNotEmpty()) {
            $averageMinutes = (int) round($loginMinutes->avg());
            $averageLoginTime = sprintf('%02d:%02d', intdiv($averageMinutes, 60), $averageMinutes % 60);
        }

        return [
            'on_time_percentage' => $evaluatedCount > 0
                ? round(($onTimeCount / $evaluatedCount) * 100, 1)
                : null,
            'on_time_label' => $evaluatedCount > 0
                ? round(($onTimeCount / $evaluatedCount) * 100).'%'
                : '—',
            'average_login_time' => $averageLoginTime,
            'late_days' => $lateDays,
            'evaluated_days' => $evaluatedCount,
        ];
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @param  list<array{date: Carbon, is_working_day: bool, is_holiday: bool, is_leave: bool, is_present: bool}>  $dayBreakdown
     * @return array<string, int|string>
     */
    private function buildPresenceMetrics(
        Collection $sessions,
        array $dayBreakdown,
        ?TeamMemberWorkSchedule $schedule,
    ): array {
        $expectedWorkingSeconds = (int) $sessions->sum(
            fn (WorkSession $session): int => ((int) $session->expected_working_minutes) * 60,
        );

        if ($expectedWorkingSeconds === 0 && $schedule !== null) {
            $expectedMinutesPerDay = $this->workCalendarService->expectedWorkingMinutes($schedule);
            $expectedWorkingDays = collect($dayBreakdown)
                ->where('is_working_day', true)
                ->where('is_leave', false)
                ->count();
            $expectedWorkingSeconds = $expectedMinutesPerDay * $expectedWorkingDays * 60;
        }

        $activeSeconds = (int) $sessions->sum('active_duration_seconds');
        $breakSeconds = (int) $sessions->sum('break_duration_seconds');
        $lunchSeconds = (int) $sessions->sum('lunch_duration_seconds');
        $extraIdleSeconds = (int) $sessions->sum('extra_idle_duration_seconds');
        $overtimeSeconds = (int) $sessions->sum('overtime_seconds');
        $presentDays = collect($dayBreakdown)->where('is_present', true)->count();

        return [
            'expected_working_seconds' => $expectedWorkingSeconds,
            'expected_working_label' => $this->presenceEngineService->formatDuration($expectedWorkingSeconds),
            'active_desk_seconds' => $activeSeconds,
            'active_desk_label' => $this->presenceEngineService->formatDuration($activeSeconds),
            'active_desk_average_label' => $presentDays > 0
                ? $this->presenceEngineService->formatDuration((int) round($activeSeconds / $presentDays))
                : '—',
            'break_seconds' => $breakSeconds,
            'break_label' => $this->presenceEngineService->formatDuration($breakSeconds),
            'lunch_seconds' => $lunchSeconds,
            'lunch_label' => $this->presenceEngineService->formatDuration($lunchSeconds),
            'extra_idle_seconds' => $extraIdleSeconds,
            'extra_idle_label' => $this->presenceEngineService->formatDuration($extraIdleSeconds),
            'overtime_seconds' => $overtimeSeconds,
            'overtime_label' => $this->presenceEngineService->formatDuration($overtimeSeconds),
        ];
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @return array<string, int|string|null>
     */
    private function buildCustomerWorkMetrics(
        User $user,
        Collection $sessions,
        PerformancePeriodRange $range,
    ): array {
        $casesHandled = (int) $sessions->sum('cases_handled_count');
        $communications = (int) $sessions->sum('communication_events_count');
        $casesCompleted = $this->completedCasesQuery($user, $range)->count();
        $appointmentsHandled = SupportAppointment::query()
            ->whereHas('incident', fn ($query) => $query->where('assigned_to_user_id', $user->id))
            ->whereBetween('created_at', [$range->start, $range->end])
            ->count();

        $resolutionMinutes = $this->averageResolutionMinutes($user, $range);

        return [
            'cases_handled' => $casesHandled,
            'cases_completed' => $casesCompleted,
            'customer_communications' => $communications,
            'support_appointments_handled' => $appointmentsHandled,
            'average_resolution_minutes' => $resolutionMinutes,
            'average_resolution_label' => $resolutionMinutes !== null
                ? $this->formatMinutesLabel((int) round($resolutionMinutes))
                : '—',
        ];
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function buildQualityMetrics(User $user, PerformancePeriodRange $range): array
    {
        $completedCases = $this->completedCasesQuery($user, $range)->get();
        $slaEvaluated = 0;
        $slaSuccessful = 0;

        foreach ($completedCases as $incident) {
            if ($incident->created_at === null || $incident->updated_at === null) {
                continue;
            }

            $slaStatus = $incident->slaStatus($incident->updated_at);

            if ($slaStatus === ServiceCaseSlaStatus::Paused) {
                continue;
            }

            $slaEvaluated++;
            if ($slaStatus === ServiceCaseSlaStatus::WithinSla) {
                $slaSuccessful++;
            }
        }

        $overdueCases = Incident::query()
            ->where('assigned_to_user_id', $user->id)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->get()
            ->filter(fn (Incident $incident): bool => $incident->slaStatus() === ServiceCaseSlaStatus::Overdue)
            ->count();

        $reopenedCases = AuditLog::query()
            ->where('user_id', $user->id)
            ->where('event', 'service_case.status_changed')
            ->where('auditable_type', Incident::class)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->get()
            ->filter(function (AuditLog $log): bool {
                $oldStatus = $log->old_values['status'] ?? null;
                $newStatus = $log->new_values['status'] ?? null;

                return $oldStatus === IncidentStatus::Closed->value
                    && $newStatus === IncidentStatus::Open->value;
            })
            ->count();

        $escalationCount = Incident::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('high_priority', true)
            ->whereBetween('created_at', [$range->start, $range->end])
            ->count()
            + ServiceCaseCloseException::query()
                ->where('created_by', $user->id)
                ->whereBetween('created_at', [$range->start, $range->end])
                ->count();

        return [
            'sla_success_percentage' => $slaEvaluated > 0
                ? round(($slaSuccessful / $slaEvaluated) * 100, 1)
                : null,
            'sla_label' => $slaEvaluated > 0
                ? round(($slaSuccessful / $slaEvaluated) * 100).'%'
                : '—',
            'overdue_cases' => $overdueCases,
            'reopened_cases' => $reopenedCases,
            'escalation_count' => $escalationCount,
            'sla_evaluated_cases' => $slaEvaluated,
        ];
    }

    public function averageResolutionMinutes(User $user, PerformancePeriodRange $range): ?float
    {
        $durations = $this->completedCasesQuery($user, $range)
            ->get(['created_at', 'updated_at'])
            ->map(function (Incident $incident): ?int {
                if ($incident->created_at === null || $incident->updated_at === null) {
                    return null;
                }

                return max(0, (int) $incident->created_at->diffInMinutes($incident->updated_at));
            })
            ->filter(fn (?int $minutes): bool => $minutes !== null);

        if ($durations->isEmpty()) {
            return null;
        }

        return round((float) $durations->avg(), 1);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Incident>
     */
    private function completedCasesQuery(User $user, PerformancePeriodRange $range)
    {
        return Incident::query()
            ->where('updated_by', $user->id)
            ->whereIn('status', [IncidentStatus::Closed, IncidentStatus::Resolved])
            ->whereBetween('updated_at', [$range->start, $range->end]);
    }

    private function formatMinutesLabel(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($remaining === 0) {
            return $hours.'h';
        }

        return sprintf('%dh %dm', $hours, $remaining);
    }
}
