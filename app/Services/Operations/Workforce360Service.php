<?php

namespace App\Services\Operations;

use App\Data\Operations\TeamMemberPerformanceMetrics;
use App\Data\Operations\Workforce360MemberData;
use App\Data\Operations\Workforce360TeamData;
use App\Enums\LeaveRequestStatus;
use App\Enums\PerformancePeriod;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
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
        $onShift = $trackedMembers
            ->filter(fn (User $member): bool => $this->workCalendarService->isOnScheduledShift($member, $at))
            ->count();
        $onLeave = $trackedMembers
            ->filter(fn (User $member): bool => $this->workCalendarService->hasApprovedLeave($member, $at))
            ->count();
        $pendingLeave = LeaveRequest::query()
            ->where('status', LeaveRequestStatus::Pending)
            ->count();

        $heroMetrics = [
            'on_duty' => count($onDuty),
            'on_shift' => $onShift,
            'on_leave' => $onLeave,
            'pending_leave' => $pendingLeave,
        ];

        return new Workforce360TeamData(
            asOf: $at->copy(),
            hero: [
                'score' => $this->scorePlaceholder(),
                'metrics' => $heroMetrics,
                'summary' => sprintf(
                    'On duty %d · On shift %d · On leave %d · Pending leave %d',
                    $heroMetrics['on_duty'],
                    $heroMetrics['on_shift'],
                    $heroMetrics['on_leave'],
                    $heroMetrics['pending_leave'],
                ),
            ],
            capacity: [
                [
                    'key' => 'on_duty',
                    'label' => 'On Duty',
                    'value' => $heroMetrics['on_duty'],
                    'tone' => 'healthy',
                ],
                [
                    'key' => 'unavailable',
                    'label' => 'Unavailable',
                    'value' => count($unavailable),
                    'tone' => 'warning',
                ],
                [
                    'key' => 'on_leave',
                    'label' => 'On Leave',
                    'value' => $heroMetrics['on_leave'],
                    'tone' => 'info',
                ],
                [
                    'key' => 'exceptions',
                    'label' => 'Exceptions',
                    'value' => $pendingLeave,
                    'tone' => $pendingLeave > 0 ? 'danger' : 'healthy',
                ],
            ],
            members: $this->teamMemberRows($viewer, $onDuty, $unavailable),
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
     * @return list<array<string, mixed>>
     */
    private function teamMemberRows(User $viewer, array $onDuty, array $unavailable): array
    {
        $rows = [];

        foreach ([...$onDuty, ...$unavailable] as $member) {
            $memberUser = User::query()->find($member['id'] ?? null);

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
            ];
        }

        return $rows;
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
