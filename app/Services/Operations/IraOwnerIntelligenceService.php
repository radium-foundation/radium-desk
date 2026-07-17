<?php

namespace App\Services\Operations;

use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOwnerReportData;
use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\OperationQueue;
use App\Enums\PerformancePeriod;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IraOwnerIntelligenceService
{
    public function __construct(
        private readonly IraOperationsBrainService $brainService,
        private readonly IraMemoryService $memoryService,
        private readonly TeamAvailabilityOverviewService $availabilityOverviewService,
        private readonly TeamPerformanceMetricsService $performanceMetricsService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
        private readonly ProductionEveningHealthService $eveningHealthService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
    ) {}

    public function buildMorningReport(?Carbon $at = null): IraOwnerReportData
    {
        $at ??= now();
        $briefing = $this->brainService->briefing($at, useCache: false);
        $snapshot = $briefing->snapshot;

        return new IraOwnerReportData(
            date: $snapshot->date,
            period: 'morning',
            team: $this->buildMorningTeamSection($at),
            operations: $this->buildMorningOperationsSection($briefing, $at),
            previousDay: $this->buildPreviousDaySection($briefing, $at),
            attendance: $this->emptyAttendanceSection(),
            people: $this->emptyPeopleSection(),
            snapshot: $snapshot,
        );
    }

    public function buildEveningReport(?Carbon $at = null): IraOwnerReportData
    {
        $at ??= now();
        $this->memoryService->ensureTodaySnapshot($at);
        $snapshot = $this->memoryService->collectSnapshotData($at);

        return new IraOwnerReportData(
            date: $snapshot->date,
            period: 'evening',
            team: $this->buildEveningTeamSection($at),
            operations: $this->buildEveningOperationsSection($at),
            previousDay: $this->emptyPreviousDaySection(),
            attendance: $this->buildAttendanceSection($at),
            people: $this->buildPeopleSection($at),
            snapshot: $snapshot,
            systemHealth: $this->eveningHealthService->build($at),
        );
    }

    /**
     * @return array{
     *     present: list<string>,
     *     absent: list<string>,
     *     on_leave: list<string>,
     *     late_arrivals: list<string>,
     *     pending_leave_approvals: int,
     *     pending_leave_requesters: list<string>,
     * }
     */
    private function buildMorningTeamSection(Carbon $at): array
    {
        $overview = $this->availabilityOverviewService->overview();
        $onLeaveNames = $this->onLeaveMemberNames($at);
        $lateArrivals = $this->lateArrivalNames($at);
        $pendingLeave = LeaveRequest::query()
            ->with('user')
            ->where('status', LeaveRequestStatus::Pending)
            ->orderBy('start_date')
            ->get();

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

        return [
            'present' => $present,
            'absent' => $absent,
            'on_leave' => $onLeaveNames,
            'late_arrivals' => $lateArrivals,
            'pending_leave_approvals' => $pendingLeave->count(),
            'pending_leave_requesters' => $pendingLeave
                ->map(fn (LeaveRequest $request): string => $request->user?->name ?? 'Unknown')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{
     *     present: list<string>,
     *     absent: list<string>,
     *     on_leave: list<string>,
     *     late_arrivals: list<string>,
     *     pending_leave_approvals: int,
     *     pending_leave_requesters: list<string>,
     * }
     */
    private function buildEveningTeamSection(Carbon $at): array
    {
        return $this->buildMorningTeamSection($at);
    }

    /**
     * @return array{
     *     open_cases: int,
     *     sla_overdue: int,
     *     sla_warning: int,
     *     overdue_cases: int,
     *     escalations_pending: int,
     *     unassigned_important: int,
     *     waiting_customers: int,
     *     cases_created: int,
     *     cases_closed: int,
     *     escalated_today: int,
     * }
     */
    private function buildMorningOperationsSection(IraMorningBriefing $briefing, Carbon $at): array
    {
        $operations = $briefing->snapshot->operations;
        $dashboard = DashboardSnapshot::load();
        $slaCounts = $dashboard->slaCounts($at);

        return [
            'open_cases' => (int) ($operations['open_cases'] ?? $dashboard->openCount()),
            'sla_overdue' => (int) ($operations['overdue'] ?? $slaCounts['service_overdue_cases'] ?? 0),
            'sla_warning' => (int) ($operations['warning'] ?? $slaCounts['service_warning_cases'] ?? 0),
            'overdue_cases' => (int) ($operations['total_overdue_cases'] ?? $slaCounts['overdue_cases'] ?? 0),
            'escalations_pending' => $this->pendingEscalationCount(),
            'unassigned_important' => $this->unassignedImportantCount($dashboard),
            'waiting_customers' => (int) ($operations['waiting'] ?? 0),
            'cases_created' => 0,
            'cases_closed' => 0,
            'escalated_today' => 0,
        ];
    }

    /**
     * @return array{
     *     open_cases: int,
     *     sla_overdue: int,
     *     sla_warning: int,
     *     overdue_cases: int,
     *     escalations_pending: int,
     *     unassigned_important: int,
     *     waiting_customers: int,
     *     cases_created: int,
     *     cases_closed: int,
     *     escalated_today: int,
     * }
     */
    private function buildEveningOperationsSection(Carbon $at): array
    {
        $dashboard = DashboardSnapshot::load();
        $slaCounts = $dashboard->slaCounts($at);
        $rangeStart = $at->copy()->startOfDay();
        $rangeEnd = $at->copy()->endOfDay();

        return [
            'open_cases' => $dashboard->openCount(),
            'sla_overdue' => (int) ($slaCounts['service_overdue_cases'] ?? 0),
            'sla_warning' => (int) ($slaCounts['service_warning_cases'] ?? 0),
            'overdue_cases' => (int) ($slaCounts['overdue_cases'] ?? 0),
            'escalations_pending' => $this->pendingEscalationCount(),
            'unassigned_important' => $this->unassignedImportantCount($dashboard),
            'waiting_customers' => $dashboard->queueCounts()[OperationQueue::WaitingCustomer->value] ?? 0,
            'cases_created' => Incident::query()
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->count(),
            'cases_closed' => Incident::query()
                ->whereIn('status', [IncidentStatus::Closed, IncidentStatus::Resolved])
                ->whereBetween('updated_at', [$rangeStart, $rangeEnd])
                ->count(),
            'escalated_today' => AuditLog::query()
                ->where('event', 'service_case.escalated')
                ->where('auditable_type', Incident::class)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->count(),
        ];
    }

    /**
     * @return array{unresolved_carry_forward: int, critical_events: list<string>}
     */
    private function buildPreviousDaySection(IraMorningBriefing $briefing, Carbon $at): array
    {
        $yesterday = $this->memoryService->yesterdaySnapshot($at);
        $carryForward = 0;

        if ($yesterday !== null) {
            $yesterdayOpen = (int) ($yesterday->operations['open_cases'] ?? 0);
            $todayOpen = (int) ($briefing->snapshot->operations['open_cases'] ?? 0);
            $carryForward = max(0, min($yesterdayOpen, $todayOpen));
        }

        $criticalEvents = [];

        foreach ($briefing->risks as $risk) {
            if ($risk->severity !== AIRiskLevel::High) {
                continue;
            }

            $criticalEvents[] = $risk->message;
        }

        return [
            'unresolved_carry_forward' => $carryForward,
            'critical_events' => array_slice($criticalEvents, 0, 3),
        ];
    }

    /**
     * @return array{
     *     on_time_logins: int,
     *     late_logins: int,
     *     manual_logouts: int,
     *     timeout_events: int,
     *     extra_working_members: list<array{name: string, overtime_label: string}>,
     *     away_timeout_members: list<array{name: string, timeout_count: int}>,
     * }
     */
    private function buildAttendanceSection(Carbon $at): array
    {
        $attendanceDays = $this->attendanceRegisterService->resolveTrackedDaysOnDate(
            workDate: $at,
            referenceAt: $at,
        );

        $evaluated = $attendanceDays->filter(
            fn (WorkforceAttendanceDay $day): bool => $day->on_time_login !== null,
        );

        $extraWorking = [];
        $awayTimeouts = [];

        foreach ($this->performanceMetricsService->teamMetrics(PerformancePeriod::Today, null, null, $at) as $metrics) {
            $overtimeSeconds = (int) ($metrics->presence['overtime_seconds'] ?? 0);

            if ($overtimeSeconds > 0) {
                $extraWorking[] = [
                    'name' => $metrics->name,
                    'overtime_label' => (string) ($metrics->presence['overtime_label'] ?? ''),
                ];
            }

            $timeoutCount = $this->timeoutCountForUser($metrics->userId, $at, $attendanceDays);

            if ($timeoutCount > 0) {
                $awayTimeouts[] = [
                    'name' => $metrics->name,
                    'timeout_count' => $timeoutCount,
                ];
            }
        }

        return [
            'on_time_logins' => $evaluated->where('on_time_login', true)->count(),
            'late_logins' => $evaluated->where('on_time_login', false)->count(),
            'manual_logouts' => (int) $attendanceDays->sum('manual_logout_count'),
            'timeout_events' => (int) $attendanceDays->sum('away_timeout_count'),
            'extra_working_members' => array_slice($extraWorking, 0, 5),
            'away_timeout_members' => array_slice($awayTimeouts, 0, 5),
        ];
    }

    /**
     * @return array{
     *     highlights: list<array{name: string, metric: string, value: int|string}>,
     *     bottlenecks: list<array{name: string, metric: string, value: int|string}>,
     * }
     */
    private function buildPeopleSection(Carbon $at): array
    {
        $highlights = [];
        $bottlenecks = [];

        foreach ($this->performanceMetricsService->teamMetrics(PerformancePeriod::Today, null, null, $at) as $metrics) {
            $this->appendPeopleSignals($metrics, $highlights, $bottlenecks);
        }

        usort($highlights, fn (array $left, array $right): int => (int) $right['value'] <=> (int) $left['value']);
        usort($bottlenecks, fn (array $left, array $right): int => (int) $right['value'] <=> (int) $left['value']);

        return [
            'highlights' => array_slice($highlights, 0, 3),
            'bottlenecks' => array_slice($bottlenecks, 0, 3),
        ];
    }

    /**
     * @param  list<array{name: string, metric: string, value: int|string}>  $highlights
     * @param  list<array{name: string, metric: string, value: int|string}>  $bottlenecks
     */
    private function appendPeopleSignals(
        TeamMemberPerformanceMetrics $metrics,
        array &$highlights,
        array &$bottlenecks,
    ): void {
        $completed = (int) ($metrics->customerWork['cases_completed'] ?? 0);
        $communications = (int) ($metrics->customerWork['customer_communications'] ?? 0);
        $openOverdue = (int) ($metrics->quality['overdue_cases'] ?? 0);
        $openWork = (int) ($metrics->customerWork['cases_handled'] ?? 0);

        if ($completed >= 3) {
            $highlights[] = [
                'name' => $metrics->name,
                'metric' => 'cases closed',
                'value' => $completed,
            ];
        } elseif ($communications >= 10) {
            $highlights[] = [
                'name' => $metrics->name,
                'metric' => 'customer follow-ups',
                'value' => $communications,
            ];
        }

        if ($openOverdue >= 2) {
            $bottlenecks[] = [
                'name' => $metrics->name,
                'metric' => 'overdue cases',
                'value' => $openOverdue,
            ];
        } elseif ($openWork >= 8) {
            $bottlenecks[] = [
                'name' => $metrics->name,
                'metric' => 'open workload',
                'value' => $openWork,
            ];
        }
    }

    /**
     * @return list<string>
     */
    private function onLeaveMemberNames(Carbon $at): array
    {
        return $this->attendanceTrackedUsers()
            ->filter(fn (User $user): bool => $this->workCalendarService->hasApprovedLeave($user, $at))
            ->map(fn (User $user): string => $user->name)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function lateArrivalNames(Carbon $at): array
    {
        return $this->attendanceRegisterService
            ->resolveTrackedDaysOnDate(workDate: $at, referenceAt: $at)
            ->filter(fn (WorkforceAttendanceDay $day): bool => $day->on_time_login === false)
            ->map(fn (WorkforceAttendanceDay $day): string => $day->user?->name ?? 'Unknown')
            ->unique()
            ->values()
            ->all();
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

    private function timeoutCountForUser(
        int $userId,
        Carbon $at,
        ?\Illuminate\Support\Collection $attendanceDays = null,
    ): int {
        $attendanceDays ??= $this->attendanceRegisterService->resolveTrackedDaysOnDate(
            workDate: $at,
            referenceAt: $at,
        );

        $day = $attendanceDays->firstWhere('user_id', $userId);

        return (int) ($day?->away_timeout_count ?? 0);
    }

    /**
     * @return Collection<int, User>
     */
    private function attendanceTrackedUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user));
    }

    /**
     * @return array{
     *     on_time_logins: int,
     *     late_logins: int,
     *     manual_logouts: int,
     *     timeout_events: int,
     *     extra_working_members: list<array{name: string, overtime_label: string}>,
     *     away_timeout_members: list<array{name: string, timeout_count: int}>,
     * }
     */
    private function emptyAttendanceSection(): array
    {
        return [
            'on_time_logins' => 0,
            'late_logins' => 0,
            'manual_logouts' => 0,
            'timeout_events' => 0,
            'extra_working_members' => [],
            'away_timeout_members' => [],
        ];
    }

    /**
     * @return array{unresolved_carry_forward: int, critical_events: list<string>}
     */
    private function emptyPreviousDaySection(): array
    {
        return [
            'unresolved_carry_forward' => 0,
            'critical_events' => [],
        ];
    }

    /**
     * @return array{
     *     highlights: list<array{name: string, metric: string, value: int|string}>,
     *     bottlenecks: list<array{name: string, metric: string, value: int|string}>,
     * }
     */
    private function emptyPeopleSection(): array
    {
        return [
            'highlights' => [],
            'bottlenecks' => [],
        ];
    }
}
