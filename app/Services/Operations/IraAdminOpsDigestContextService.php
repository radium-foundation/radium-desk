<?php

namespace App\Services\Operations;

use App\Data\Operations\IraMorningBriefing;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\OperationQueue;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;

class IraAdminOpsDigestContextService
{
    private const LATE_PATTERN_LOOKBACK_DAYS = 10;

    public function __construct(
        private readonly TeamAvailabilityOverviewService $availabilityOverviewService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(IraMorningBriefing $briefing, string $period, ?Carbon $at = null): array
    {
        $at ??= now();
        $timezone = (string) config('app.schedule_timezone', config('app.timezone', 'Asia/Kolkata'));
        $at = $at->copy()->timezone($timezone);
        $operations = $briefing->snapshot->operations;
        $dashboard = DashboardSnapshot::load();

        $overloadLines = [];
        foreach ($briefing->risks as $risk) {
            if (! str_starts_with($risk->key, 'team.overload.')) {
                continue;
            }

            $overloadLines[] = $risk->message;
        }

        return [
            'period' => $period,
            'team' => $this->buildTeamSection($at),
            'operations' => [
                'open_cases' => (int) ($operations['open_cases'] ?? 0),
                'overdue' => (int) ($operations['overdue'] ?? 0),
                'warning' => (int) ($operations['warning'] ?? 0),
                'waiting' => (int) ($operations['waiting'] ?? 0),
                'missed_appointments' => (int) ($operations['missed_appointments'] ?? 0),
                'unassigned_scheduled' => $this->unassignedScheduledCount(),
                'unassigned_important' => $this->unassignedImportantCount($dashboard),
                'escalations_pending' => $this->pendingEscalationCount(),
            ],
            'refunds' => $this->buildRefundSummarySection($at),
            'overload_lines' => array_slice($overloadLines, 0, 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTeamSection(Carbon $at): array
    {
        $overview = $this->availabilityOverviewService->overview();
        $onLeaveNames = $this->onLeaveMemberNames($at);
        $attendanceDays = $this->attendanceRegisterService->resolveTrackedDaysOnDate(
            workDate: $at,
            referenceAt: $at,
        );

        $present = array_map(
            fn (array $member): string => (string) $member['name'],
            $overview['on_duty'],
        );

        $absent = array_values(array_filter(
            array_map(
                fn (array $member): string => (string) $member['name'],
                $overview['unavailable'],
            ),
            fn (string $name): bool => ! in_array($name, $onLeaveNames, true),
        ));

        $pendingLeave = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->count();

        $lateArrivals = [];
        $attendanceShortfalls = [];

        foreach ($attendanceDays as $day) {
            $user = $day->user;
            $name = $user?->name ?? 'Unknown';

            if ($day->on_time_login === false && (int) ($day->minutes_late ?? 0) > 0 && $user !== null) {
                $pattern = $this->latePatternMetrics($user, $at);
                $lateArrivals[] = [
                    'name' => $name,
                    'minutes_late' => (int) $day->minutes_late,
                    'login_at' => $day->first_login_at?->copy()->timezone($at->timezone)->format('H:i'),
                    'late_days_in_window' => $pattern['late_days'],
                    'evaluated_days' => $pattern['evaluated_days'],
                ];
            }

            if ((int) ($day->expected_working_minutes ?? 0) > 0) {
                $shortfallMinutes = max(
                    0,
                    (int) $day->expected_working_minutes - (int) floor(((int) $day->active_duration_seconds) / 60),
                );

                if ($shortfallMinutes > 0) {
                    $attendanceShortfalls[] = [
                        'name' => $name,
                        'shortfall_minutes' => $shortfallMinutes,
                    ];
                }
            }
        }

        return [
            'present' => $present,
            'absent' => $absent,
            'on_leave' => $onLeaveNames,
            'pending_leave_approvals' => $pendingLeave,
            'late_arrivals' => $lateArrivals,
            'attendance_shortfalls' => array_slice($attendanceShortfalls, 0, 5),
        ];
    }

    /**
     * @return array{late_days: int, evaluated_days: int}
     */
    private function latePatternMetrics(User $user, Carbon $at): array
    {
        $lateDays = 0;
        $evaluatedDays = 0;

        for ($offset = 0; $offset < self::LATE_PATTERN_LOOKBACK_DAYS; $offset++) {
            $date = $at->copy()->subDays($offset);

            if ($this->workCalendarService->hasApprovedLeave($user, $date)) {
                continue;
            }

            $schedule = $this->workCalendarService->scheduleFor($user, $date);

            if ($schedule === null) {
                continue;
            }

            $day = $this->attendanceRegisterService->findDay($user, $date);

            if ($day === null || $day->on_time_login === null) {
                continue;
            }

            $evaluatedDays++;

            if ($day->on_time_login === false) {
                $lateDays++;
            }
        }

        return [
            'late_days' => $lateDays,
            'evaluated_days' => $evaluatedDays,
        ];
    }

    /**
     * @return list<string>
     */
    private function onLeaveMemberNames(Carbon $at): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->workCalendarService->hasApprovedLeave($user, $at))
            ->map(fn (User $user): string => $user->name)
            ->values()
            ->all();
    }

    private function unassignedScheduledCount(): int
    {
        $snapshot = DashboardSnapshot::load();

        return $snapshot
            ->incidentsForQueue(OperationQueue::Scheduled->value)
            ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
            ->count();
    }

    private function unassignedImportantCount(DashboardSnapshot $dashboard): int
    {
        $importantQueues = [
            OperationQueue::ActionRequired->value,
            OperationQueue::Attention->value,
        ];

        $count = 0;

        foreach ($importantQueues as $queue) {
            $count += $dashboard
                ->incidentsForQueue($queue)
                ->filter(fn (Incident $incident): bool => $incident->assigned_to_user_id === null)
                ->count();
        }

        return $count;
    }

    private function pendingEscalationCount(): int
    {
        $escalatedIncidentIds = AuditLog::query()
            ->where('event', 'service_case.escalated')
            ->where('auditable_type', Incident::class)
            ->pluck('auditable_id')
            ->unique()
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($escalatedIncidentIds === []) {
            return 0;
        }

        return Incident::query()
            ->whereIn('id', $escalatedIncidentIds)
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->count();
    }

    /**
     * @return array{
     *     pending_approval: int,
     *     pending_execution: int,
     *     submitted_today: int,
     * }
     */
    private function buildRefundSummarySection(Carbon $at): array
    {
        $rangeStart = $at->copy()->startOfDay();
        $rangeEnd = $at->copy()->endOfDay();

        return [
            'pending_approval' => RefundRequest::query()
                ->where('status', RefundStatus::Pending)
                ->count(),
            'pending_execution' => RefundRequest::query()
                ->where('status', RefundStatus::PendingExecution)
                ->count(),
            'submitted_today' => RefundRequest::query()
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->count(),
        ];
    }
}
