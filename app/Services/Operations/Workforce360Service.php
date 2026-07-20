<?php

namespace App\Services\Operations;

use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Data\Operations\Workforce360MemberData;
use App\Data\Operations\Workforce360TeamData;
use App\Enums\AttendanceDayStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Policies\Workforce360Policy;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Workforce360Service
{
    public function __construct(
        private readonly TeamAvailabilityOverviewService $availabilityOverviewService,
        private readonly WorkforceAuthorityService $workforceAuthorityService,
        private readonly PresenceEngineService $presenceEngineService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamWorkScheduleService $teamWorkScheduleService,
        private readonly AttendanceRegisterService $attendanceRegisterService,
        private readonly TeamPerformanceMetricsService $performanceMetricsService,
        private readonly OperationsRoleService $roleService,
        private readonly Workforce360Policy $workforce360Policy,
    ) {}

    public function team(User $viewer, ?Carbon $at = null): Workforce360TeamData
    {
        $at ??= now();
        $overview = $this->availabilityOverviewService->overview();
        $onDuty = $overview['on_duty'] ?? [];
        $unavailable = $overview['unavailable'] ?? [];
        $trackedMembers = $this->attendanceTrackedMembers();
        $trackedIds = $trackedMembers->pluck('id')->all();
        $sessionSignals = $this->todaySessionSignals($trackedIds, $at);
        $attendanceExceptionCount = $this->attendanceExceptionCount($trackedIds, $at);

        $workforceCounts = $this->workforceTodayCounts($trackedMembers, $at);
        $pendingLeave = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->count();
        $lateLoginCount = count($sessionSignals['late_login_user_ids']);
        $sessionTimeoutCount = count($sessionSignals['timeout_user_ids']);

        $heroMetrics = [
            'available' => $workforceCounts['available'],
            'busy' => $workforceCounts['busy'],
            'offline' => $workforceCounts['offline'],
            'on_leave' => $workforceCounts['on_leave'],
            'pending_leave' => $pendingLeave,
            'late_login' => $lateLoginCount,
            'session_timeout' => $sessionTimeoutCount,
            'attendance_exception' => $attendanceExceptionCount,
        ];

        $members = $this->teamMemberRows(
            viewer: $viewer,
            onDuty: $onDuty,
            unavailable: $unavailable,
            sessionSignals: $sessionSignals,
        );

        return new Workforce360TeamData(
            asOf: $at->copy(),
            hero: [
                'score' => $this->scorePlaceholder(),
                'metrics' => $heroMetrics,
                'summary' => sprintf(
                    'Available %d · Busy %d · Offline %d · On leave %d',
                    $heroMetrics['available'],
                    $heroMetrics['busy'],
                    $heroMetrics['offline'],
                    $heroMetrics['on_leave'],
                ),
            ],
            capacity: [
                'workforce_today' => [
                    [
                        'key' => 'available',
                        'label' => 'Available',
                        'value' => $heroMetrics['available'],
                        'tone' => 'healthy',
                    ],
                    [
                        'key' => 'busy',
                        'label' => 'Busy',
                        'value' => $heroMetrics['busy'],
                        'tone' => 'warning',
                    ],
                    [
                        'key' => 'offline',
                        'label' => 'Offline',
                        'value' => $heroMetrics['offline'],
                        'tone' => 'info',
                    ],
                    [
                        'key' => 'on_leave',
                        'label' => 'On Leave',
                        'value' => $heroMetrics['on_leave'],
                        'tone' => 'info',
                    ],
                ],
                'attention_required' => [
                    [
                        'key' => 'pending_leave',
                        'label' => 'Pending Leave',
                        'value' => $pendingLeave,
                        'tone' => $pendingLeave > 0 ? 'danger' : 'healthy',
                    ],
                    [
                        'key' => 'late_login',
                        'label' => 'Late Login',
                        'value' => $lateLoginCount,
                        'tone' => $lateLoginCount > 0 ? 'warning' : 'healthy',
                    ],
                    [
                        'key' => 'session_timeout',
                        'label' => 'Session Timeout',
                        'value' => $sessionTimeoutCount,
                        'tone' => $sessionTimeoutCount > 0 ? 'danger' : 'healthy',
                    ],
                    [
                        'key' => 'attendance_exception',
                        'label' => 'Attendance Exception',
                        'value' => $attendanceExceptionCount,
                        'tone' => $attendanceExceptionCount > 0 ? 'danger' : 'healthy',
                    ],
                ],
            ],
            members: $members,
            tabs: $this->teamTabs(),
            teamAvailability: $overview,
        );
    }

    public function member(User $viewer, User $subject, ?Carbon $at = null): Workforce360MemberData
    {
        $at ??= now();
        $subject->loadMissing(['roles', 'workSchedule']);
        $isSelf = $viewer->id === $subject->id;
        $authority = $this->workforceAuthorityService->snapshotFor($subject, $at);
        $presence = $this->presenceEngineService->snapshotFor($subject, $at);
        $calendar = $this->workCalendarService->todayStatusFor($subject, $at);
        $schedule = $this->teamWorkScheduleService->snapshotFor($subject);
        $attendanceDay = $this->attendanceRegisterService->resolveDay($subject, $at->copy()->startOfDay(), $at);
        $performance = $this->performanceMetricsService->metricsFor($subject, PerformancePeriod::Today, null, null, $at);
        $openWorkCount = DashboardSnapshot::load()->openCount($subject);
        $leaveSummary = $this->leaveSummaryFor($subject, $at);

        $statusChips = $this->memberStatusChips($authority, $presence, $calendar, $attendanceDay, $openWorkCount);

        return new Workforce360MemberData(
            user: $subject,
            asOf: $at->copy(),
            isSelf: $isSelf,
            hero: [
                'score' => $this->scorePlaceholder(),
                'headline' => $this->memberHeadline($statusChips),
                'status_chips' => $statusChips,
            ],
            context: [
                'role_label' => $this->roleService->displayLabel($subject->roles->first()?->name),
                'assignment_pool' => $this->roleService->isNormalAssignmentPool($subject)
                    ? 'normal_support_pool'
                    : 'specialist_pool',
            ],
            overview: [
                'schedule' => $schedule,
                'presence' => $presence,
                'calendar' => $calendar,
                'authority' => $authority,
                'attendance_day' => $this->attendanceDaySnapshot($attendanceDay),
                'performance' => $this->performanceSnapshot($performance),
                'open_work_count' => $openWorkCount,
                'leave' => $leaveSummary,
                'block_reason_labels' => $this->blockReasonLabels($authority['block_reasons'] ?? []),
            ],
            tabs: $this->memberTabs($viewer, $subject, $isSelf),
            teamUrl: $viewer->can('workforce.view') ? route('workforce.index') : null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $onDuty
     * @param  list<array<string, mixed>>  $unavailable
     * @param  array{
     *     late_login_user_ids: list<int>,
     *     timeout_user_ids: list<int>,
     *     by_user: array<int, array{is_late_login: bool, has_session_timeout: bool, last_ended_reason: string|null, current_incident_id: int|null}>
     * }  $sessionSignals
     * @return list<array<string, mixed>>
     */
    private function teamMemberRows(
        User $viewer,
        array $onDuty,
        array $unavailable,
        array $sessionSignals,
    ): array {
        $members = [...$onDuty, ...$unavailable];
        $memberIds = collect($members)
            ->pluck('id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $usersById = User::query()
            ->whereIn('id', $memberIds)
            ->get()
            ->keyBy('id');

        $incidentIds = collect($members)
            ->map(function (array $member) use ($sessionSignals): ?int {
                $userId = (int) ($member['id'] ?? 0);
                $fromPresence = $member['presence']['current_incident_id'] ?? null;
                $fromSignals = $sessionSignals['by_user'][$userId]['current_incident_id'] ?? null;

                return $fromPresence !== null ? (int) $fromPresence : ($fromSignals !== null ? (int) $fromSignals : null);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $incidentsById = $incidentIds === []
            ? collect()
            : Incident::query()
                ->whereIn('id', $incidentIds)
                ->get(['id', 'reference_no', 'category', 'status'])
                ->keyBy('id');

        $lateLoginIds = array_fill_keys($sessionSignals['late_login_user_ids'], true);
        $timeoutIds = array_fill_keys($sessionSignals['timeout_user_ids'], true);

        $rows = [];

        foreach ($members as $member) {
            $memberId = (int) ($member['id'] ?? 0);
            $memberUser = $usersById->get($memberId);
            $signals = $sessionSignals['by_user'][$memberId] ?? [
                'is_late_login' => false,
                'has_session_timeout' => false,
                'last_ended_reason' => null,
                'current_incident_id' => null,
            ];
            $isLateLogin = isset($lateLoginIds[$memberId]) || ($signals['is_late_login'] ?? false);
            $hasSessionTimeout = isset($timeoutIds[$memberId]) || ($signals['has_session_timeout'] ?? false);
            $incidentId = $member['presence']['current_incident_id']
                ?? $signals['current_incident_id']
                ?? null;
            $incident = $incidentId !== null ? $incidentsById->get((int) $incidentId) : null;

            $rows[] = [
                ...$member,
                'score' => $this->scorePlaceholder(),
                'can_open_profile' => $memberUser !== null
                    && $this->workforce360Policy->viewMember($viewer, $memberUser),
                'profile_url' => $memberUser !== null
                    && $this->workforce360Policy->viewMember($viewer, $memberUser)
                    ? ($viewer->id === $memberUser->id
                        ? route('my-workforce.index')
                        : route('workforce.show', $memberUser))
                    : null,
                'status_reason' => $this->statusReasonForMember($member, $signals),
                'is_late_login' => $isLateLogin,
                'has_session_timeout' => $hasSessionTimeout,
                'current_case' => $this->currentCasePayload($incident),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, User>  $trackedMembers
     * @return array{available: int, busy: int, offline: int, on_leave: int}
     */
    private function workforceTodayCounts(Collection $trackedMembers, Carbon $at): array
    {
        $available = 0;
        $busy = 0;
        $offline = 0;
        $onLeave = 0;

        foreach ($trackedMembers as $member) {
            if ($this->workforceAuthorityService->isOnApprovedLeave($member, $at)) {
                $onLeave++;

                continue;
            }

            match ($this->workforceAuthorityService->effectiveAvailability($member, $at)) {
                TeamAvailabilityStatus::Available => $available++,
                TeamAvailabilityStatus::Busy => $busy++,
                TeamAvailabilityStatus::Offline => $offline++,
            };
        }

        return [
            'available' => $available,
            'busy' => $busy,
            'offline' => $offline,
            'on_leave' => $onLeave,
        ];
    }

    /**
     * @param  list<int>  $trackedIds
     * @return array{
     *     late_login_user_ids: list<int>,
     *     timeout_user_ids: list<int>,
     *     by_user: array<int, array{is_late_login: bool, has_session_timeout: bool, last_ended_reason: string|null, current_incident_id: int|null}>
     * }
     */
    private function todaySessionSignals(array $trackedIds, Carbon $at): array
    {
        if ($trackedIds === []) {
            return [
                'late_login_user_ids' => [],
                'timeout_user_ids' => [],
                'by_user' => [],
            ];
        }

        $sessions = WorkSession::query()
            ->whereIn('user_id', $trackedIds)
            ->whereDate('work_date', $at->toDateString())
            ->orderBy('login_at')
            ->get([
                'id',
                'user_id',
                'login_at',
                'logout_at',
                'ended_reason',
                'on_time_login',
                'current_incident_id',
            ])
            ->groupBy('user_id');

        $lateLoginUserIds = [];
        $timeoutUserIds = [];
        $byUser = [];

        foreach ($sessions as $userId => $userSessions) {
            $userId = (int) $userId;
            $firstSession = $userSessions->sortBy(fn (WorkSession $session): int => $session->login_at?->getTimestamp() ?? 0)->first();
            $latestSession = $userSessions->sortByDesc(fn (WorkSession $session): int => $session->login_at?->getTimestamp() ?? 0)->first();
            $closedSessions = $userSessions
                ->filter(fn (WorkSession $session): bool => $session->logout_at !== null)
                ->sortByDesc(fn (WorkSession $session): int => $session->logout_at?->getTimestamp() ?? 0);
            $lastClosed = $closedSessions->first();

            $isLateLogin = $firstSession?->on_time_login === false;
            $hasSessionTimeout = $userSessions->contains(
                fn (WorkSession $session): bool => $session->ended_reason === WorkSessionEndReason::AwayTimeout,
            );

            if ($isLateLogin) {
                $lateLoginUserIds[] = $userId;
            }

            if ($hasSessionTimeout) {
                $timeoutUserIds[] = $userId;
            }

            $openSession = $userSessions->first(fn (WorkSession $session): bool => $session->isOpen());
            $currentIncidentId = $openSession?->current_incident_id
                ?? $latestSession?->current_incident_id;

            $byUser[$userId] = [
                'is_late_login' => $isLateLogin,
                'has_session_timeout' => $hasSessionTimeout,
                'last_ended_reason' => $lastClosed?->ended_reason?->value,
                'current_incident_id' => $currentIncidentId !== null ? (int) $currentIncidentId : null,
            ];
        }

        return [
            'late_login_user_ids' => $lateLoginUserIds,
            'timeout_user_ids' => $timeoutUserIds,
            'by_user' => $byUser,
        ];
    }

    /**
     * @param  list<int>  $trackedIds
     */
    private function attendanceExceptionCount(array $trackedIds, Carbon $at): int
    {
        if ($trackedIds === []) {
            return 0;
        }

        return WorkforceAttendanceDay::query()
            ->whereIn('user_id', $trackedIds)
            ->whereDate('work_date', $at->toDateString())
            ->where('status', AttendanceDayStatus::Away)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $member
     * @param  array{is_late_login: bool, has_session_timeout: bool, last_ended_reason: string|null, current_incident_id: int|null}  $signals
     */
    private function statusReasonForMember(array $member, array $signals): ?string
    {
        $calendarStatus = (string) (($member['work_calendar']['status'] ?? ''));
        $availability = $member['availability'] ?? [];
        $authority = $member['authority'] ?? [];
        $blockReasons = $authority['block_reasons'] ?? [];
        $unavailabilityLabel = (string) ($member['unavailability_label'] ?? '');
        $lastEndedReason = $signals['last_ended_reason']
            ?? ($member['session_summary']['last_ended_reason'] ?? null);

        if (
            ($availability['on_leave'] ?? false) === true
            || ($authority['on_approved_leave'] ?? false) === true
            || $calendarStatus === WorkCalendarDayStatus::LeaveApproved->value
        ) {
            return 'On Leave';
        }

        if ($calendarStatus === WorkCalendarDayStatus::Lunch->value) {
            return 'Lunch';
        }

        if (
            $lastEndedReason === WorkSessionEndReason::AwayTimeout->value
            || $unavailabilityLabel === 'Session timed out'
        ) {
            return 'Session Timed Out';
        }

        if (
            $lastEndedReason === WorkSessionEndReason::ManualLogout->value
            || $unavailabilityLabel === 'Logged out during shift'
        ) {
            return 'Logged Out';
        }

        if ($calendarStatus === WorkCalendarDayStatus::StartsLater->value) {
            return 'Shift Not Started';
        }

        if ($unavailabilityLabel === 'Not logged in' || in_array('not_present', $blockReasons, true)) {
            return 'Not logged in';
        }

        if ($unavailabilityLabel === 'Marked offline' || in_array('availability_offline', $blockReasons, true)) {
            return 'Marked offline';
        }

        if ($unavailabilityLabel !== '') {
            return $unavailabilityLabel;
        }

        return null;
    }

    /**
     * @return array{reference: string, category: string|null, status_label: string}|null
     */
    private function currentCasePayload(?Incident $incident): ?array
    {
        if ($incident === null) {
            return null;
        }

        $reference = trim((string) ($incident->display_reference ?: $incident->reference_no));

        if ($reference === '') {
            return null;
        }

        return [
            'reference' => $reference,
            'category' => filled($incident->category) ? (string) $incident->category : null,
            'status_label' => $incident->status?->label() ?? '—',
        ];
    }

    /**
     * @return list<array<string, string|bool>>
     */
    private function teamTabs(): array
    {
        return [
            ['key' => 'overview', 'label' => 'Overview', 'active' => true, 'placeholder' => false],
            ['key' => 'timeline', 'label' => 'Timeline', 'active' => false, 'placeholder' => true],
            ['key' => 'leave', 'label' => 'Leave Queue', 'active' => false, 'placeholder' => false, 'href' => route('leave-requests.index')],
            ['key' => 'holidays', 'label' => 'Holidays', 'active' => false, 'placeholder' => false, 'href' => route('admin.workforce.holidays.index')],
        ];
    }

    /**
     * @return list<array<string, string|bool>>
     */
    private function memberTabs(User $viewer, User $subject, bool $isSelf): array
    {
        $tabs = [
            ['key' => 'overview', 'label' => 'Overview', 'active' => true, 'placeholder' => false],
            ['key' => 'schedule', 'label' => 'Schedule', 'active' => false, 'placeholder' => false],
            ['key' => 'attendance', 'label' => 'Attendance', 'active' => false, 'placeholder' => false],
            ['key' => 'leave', 'label' => 'Leave', 'active' => false, 'placeholder' => false],
            ['key' => 'workload', 'label' => 'Workload', 'active' => false, 'placeholder' => false],
            ['key' => 'timeline', 'label' => 'Timeline', 'active' => false, 'placeholder' => true],
        ];

        if ($viewer->can('team-performance.view') || $isSelf) {
            $tabs[] = [
                'key' => 'performance',
                'label' => 'Performance',
                'active' => false,
                'placeholder' => false,
                'href' => $isSelf
                    ? route('my-performance.index')
                    : route('admin.workforce.performance.index'),
            ];
        }

        return $tabs;
    }

    /**
     * @param  list<array{label: string, tone: string}>  $chips
     */
    private function memberHeadline(array $chips): string
    {
        return collect($chips)
            ->pluck('label')
            ->filter()
            ->take(3)
            ->implode(' · ');
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $presence
     * @param  array<string, mixed>  $calendar
     * @return list<array{label: string, tone: string}>
     */
    private function memberStatusChips(
        array $authority,
        array $presence,
        array $calendar,
        ?WorkforceAttendanceDay $attendanceDay,
        int $openWorkCount,
    ): array {
        $chips = [];

        if (($authority['on_duty'] ?? false) === true) {
            $chips[] = ['label' => 'On duty', 'tone' => 'healthy'];
        } elseif (($authority['on_approved_leave'] ?? false) === true) {
            $chips[] = ['label' => 'On leave', 'tone' => 'info'];
        } else {
            $chips[] = ['label' => 'Off duty', 'tone' => 'warning'];
        }

        if ($attendanceDay?->on_time_login === true) {
            $chips[] = ['label' => 'On time', 'tone' => 'healthy'];
        } elseif ($attendanceDay?->on_time_login === false) {
            $chips[] = ['label' => 'Late', 'tone' => 'warning'];
        } elseif (in_array($calendar['status'] ?? null, ['weekly_off', 'holiday'], true)) {
            $chips[] = ['label' => 'Scheduled off', 'tone' => 'info'];
        }

        $chips[] = [
            'label' => $openWorkCount.' open '.($openWorkCount === 1 ? 'case' : 'cases'),
            'tone' => $openWorkCount >= 6 ? 'danger' : ($openWorkCount >= 3 ? 'warning' : 'info'),
        ];

        if (($presence['session_open'] ?? false) === true && filled($presence['login_at'] ?? null)) {
            $chips[] = ['label' => 'Logged in '.$presence['login_at'], 'tone' => 'info'];
        }

        return $chips;
    }

    /**
     * @return array<string, mixed>
     */
    private function scorePlaceholder(): array
    {
        return [
            'value' => null,
            'label' => 'Coming in Sprint 3',
            'tone' => 'placeholder',
        ];
    }

    /**
     * @return array<string, int|list<array<string, mixed>>|null>
     */
    private function leaveSummaryFor(User $subject, Carbon $at): array
    {
        $requests = LeaveRequest::query()
            ->where('user_id', $subject->id)
            ->orderByDesc('start_date')
            ->limit(5)
            ->get();

        $active = $requests->first(
            fn (LeaveRequest $leave): bool => $leave->status === LeaveRequestStatus::Approved
                && $at->between(
                    $leave->start_date->copy()->startOfDay(),
                    $leave->end_date->copy()->endOfDay(),
                ),
        );

        return [
            'pending_count' => $requests->where('status', LeaveRequestStatus::Pending)->count(),
            'active' => $active !== null ? [
                'start_date' => $active->start_date->toDateString(),
                'end_date' => $active->end_date->toDateString(),
                'reason' => $active->reason,
            ] : null,
            'recent' => $requests->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
                'status' => $leave->status->value,
                'status_label' => $leave->status->label(),
                'reason' => $leave->reason,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attendanceDaySnapshot(?WorkforceAttendanceDay $attendanceDay): ?array
    {
        if ($attendanceDay === null) {
            return null;
        }

        return [
            'status' => $attendanceDay->status->value,
            'status_label' => $attendanceDay->status->label(),
            'on_time_login' => $attendanceDay->on_time_login,
            'minutes_late' => $attendanceDay->minutes_late,
            'first_login_at' => $attendanceDay->first_login_at?->format('H:i'),
            'last_logout_at' => $attendanceDay->last_logout_at?->format('H:i'),
            'active_duration_seconds' => $attendanceDay->active_duration_seconds,
            'overtime_seconds' => $attendanceDay->overtime_seconds,
            'session_count' => $attendanceDay->session_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function performanceSnapshot(TeamMemberPerformanceMetrics $metrics): array
    {
        return [
            'attendance_label' => $metrics->attendance['attendance_label'] ?? '—',
            'active_desk_label' => $metrics->presence['active_desk_label'] ?? '—',
            'cases_completed' => $metrics->customerWork['cases_completed'] ?? 0,
            'customer_communications' => $metrics->customerWork['customer_communications'] ?? 0,
            'sla_label' => $metrics->quality['sla_label'] ?? '—',
        ];
    }

    /**
     * @param  list<string>  $blockReasons
     * @return list<string>
     */
    private function blockReasonLabels(array $blockReasons): array
    {
        $labels = [
            'calendar_blocked' => 'Outside scheduled working window',
            'approved_leave' => 'On approved leave',
            'not_present' => 'Not logged in to workforce session',
            'availability_offline' => 'Marked offline or busy',
            'inactive_user' => 'User account inactive',
            'not_assignment_pool' => 'Not in normal assignment pool',
        ];

        return collect($blockReasons)
            ->map(fn (string $reason): string => $labels[$reason] ?? str_replace('_', ' ', $reason))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function attendanceTrackedMembers(): Collection
    {
        return User::query()
            ->with(['roles', 'workSchedule'])
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user));
    }
}
