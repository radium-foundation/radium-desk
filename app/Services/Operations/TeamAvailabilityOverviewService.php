<?php

namespace App\Services\Operations;

use App\Data\Operations\WorkingHoursToday;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkSession;
use App\ReadModels\Cases\CaseQueueReadModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamAvailabilityOverviewService
{
    /** @var Collection<int, User>|null */
    private ?Collection $cachedTeamMembers = null;

    /** @var array{on_duty: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}|null */
    private ?array $cachedOverview = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $cachedOperationalRoster = null;

    /** @var Collection<int, Collection<int, WorkSession>>|null */
    private ?Collection $cachedTodaySessionsByUser = null;

    private ?string $cachedTodaySessionsUserKey = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $cachedTodaySessionSummaries = null;

    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamMemberActivityService $activityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly WorkforceAuthorityService $workforceAuthority,
        private readonly CaseQueueReadModel $caseQueue,
        private readonly WorkingHoursTodayService $workingHoursToday,
    ) {}

    /**
     * @return array{on_duty: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        return $this->cachedOverview ??= $this->buildOverview();
    }

    /**
     * Attendance-tracked team members for the current request (shared with Workforce360).
     *
     * @return Collection<int, User>
     */
    public function trackedMembers(): Collection
    {
        return $this->teamMembers();
    }

    /**
     * Full attendance-tracked operational roster for Team Activity.
     * Every tracked member appears exactly once regardless of on-duty state.
     *
     * @return list<array<string, mixed>>
     */
    public function operationalRoster(): array
    {
        return $this->cachedOperationalRoster ??= $this->buildOperationalRoster();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildOperationalRoster(): array
    {
        $teamMembers = $this->teamMembers();

        if ($teamMembers->isEmpty()) {
            return [];
        }

        $userIds = $teamMembers->map(fn (User $user): int => $user->id)->all();
        $openCounts = $this->caseQueue->forTeamMembers($teamMembers);
        $sessionSummaries = $this->todaySessionSummariesFor($userIds);
        $leaveReasons = $this->approvedLeaveReasonsFor($userIds);
        $hoursByUser = $this->workingHoursToday->forUsers(
            $userIds,
            preresolvedOpenUserIds: $this->openWorkSessionUserIds($userIds),
        );

        $rows = [];

        foreach ($teamMembers as $user) {
            $sessionSummary = $sessionSummaries[$user->id] ?? $this->emptySessionSummary();
            $row = $this->memberRow(
                $user,
                $openCounts[$user->id] ?? 0,
                $hoursByUser[$user->id] ?? null,
            );

            $rows[] = [
                ...$row,
                'leave_reason' => $leaveReasons[$user->id] ?? null,
                'shift_times' => $this->shiftTimeLabelsFor($user),
                'unavailability_label' => $row['on_duty']
                    ? null
                    : $this->unavailabilityLabel($row['authority'], $sessionSummary),
                'session_summary' => $sessionSummary,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function approvedLeaveReasonsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $date = now()->toDateString();

        return LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', LeaveRequestStatus::Approved)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('reason', 'user_id')
            ->map(static fn (mixed $reason): string => trim((string) $reason))
            ->filter(static fn (string $reason): bool => $reason !== '')
            ->all();
    }

    /**
     * @return array{start: string|null, end: string|null}
     */
    private function shiftTimeLabelsFor(User $user): array
    {
        $schedule = $this->workCalendarService->scheduleFor($user, now());

        if ($schedule === null) {
            return ['start' => null, 'end' => null];
        }

        return [
            'start' => $this->formatShiftClock($schedule->work_start_time),
            'end' => $this->formatShiftClock($schedule->work_end_time),
        ];
    }

    private function formatShiftClock(mixed $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        try {
            $value = $time instanceof Carbon
                ? $time->format('H:i:s')
                : (string) $time;

            if (strlen($value) === 5) {
                $value .= ':00';
            }

            return Carbon::today()
                ->setTimeFromTimeString($value)
                ->format('g:i A');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(): array
    {
        return $this->overview()['on_duty'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unavailableMembers(): array
    {
        return $this->overview()['unavailable'];
    }

    /**
     * @return array<string, mixed>
     */
    public function memberSnapshot(User $user): array
    {
        return $this->memberRow($user, $this->caseQueue->forUser($user)->openCount());
    }

    /**
     * @return array{on_duty: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}
     */
    private function buildOverview(): array
    {
        $teamMembers = $this->teamMembers();
        // H4-6D: one CaseQueueReadModel pass for the whole team (DashboardSnapshot owner).
        $openCounts = $this->caseQueue->forTeamMembers($teamMembers);
        $userIds = $teamMembers->map(fn (User $user): int => $user->id)->all();
        $sessionSummaries = $this->todaySessionSummariesFor($userIds);
        $hoursByUser = $this->workingHoursToday->forUsers(
            $userIds,
            preresolvedOpenUserIds: $this->openWorkSessionUserIds($userIds),
        );

        $onDuty = [];
        $unavailable = [];

        foreach ($teamMembers as $user) {
            $openCount = $openCounts[$user->id] ?? 0;
            $row = $this->memberRow($user, $openCount, $hoursByUser[$user->id] ?? null);

            if ($row['on_duty'] === true) {
                $onDuty[] = $row;

                continue;
            }

            // Include approved-leave agents during their expected shift window.
            // isOnScheduledShift() is false while on leave, which previously hid them.
            if ($this->workCalendarService->isExpectedOnDutyWindow($user)) {
                $sessionSummary = $sessionSummaries[$user->id] ?? $this->emptySessionSummary();
                $unavailable[] = [
                    ...$row,
                    'unavailability_label' => $this->unavailabilityLabel($row['authority'], $sessionSummary),
                    'session_summary' => $sessionSummary,
                ];
            }
        }

        return [
            'on_duty' => $onDuty,
            'unavailable' => $unavailable,
        ];
    }

    /**
     * @return Collection<int, User>
     */
    private function teamMembers(): Collection
    {
        return $this->cachedTeamMembers ??= User::query()
            ->with(['roles', 'workSchedule'])
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(User $user, int $openWorkCount, ?WorkingHoursToday $hours = null): array
    {
        $authority = $this->workforceAuthority->snapshotFor($user);
        $storedAvailability = $authority['availability'] ?? $this->availabilityService->snapshotFor($user);
        $effectiveStatus = TeamAvailabilityStatus::from($authority['effective_availability']);
        $workCalendar = $authority['work_calendar'] ?? $this->workCalendarService->todayStatusFor($user);
        $presence = $authority['presence'] ?? $this->presenceEngine->snapshotFor($user);
        $activity = $this->activityService->snapshotFor($user);
        $workActivity = $this->activityService->primaryWorkActivity($user);
        $workingHours = $hours ?? $this->workingHoursToday->forUser($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role_label' => $user->primaryRoleLabel(),
            'availability' => [
                ...$storedAvailability,
                'status' => $effectiveStatus->value,
                'label' => $effectiveStatus->label(),
                'badge_class' => $effectiveStatus->badgeClass(),
                'stored_status' => $storedAvailability['status'],
                'stored_label' => $storedAvailability['label'],
                'stored_badge_class' => $storedAvailability['badge_class'],
            ],
            'on_duty' => $authority['on_duty'],
            'authority' => $authority,
            'work_calendar' => $workCalendar,
            'presence' => $presence,
            'working_hours_today' => $workingHours->toArray(),
            'last_active_at' => $user->last_active_at,
            'last_active_relative' => $user->last_active_at !== null
                ? display_app_timeline_relative($user->last_active_at)
                : null,
            'work_activity_label' => $workActivity['label'] ?? null,
            'work_activity_at' => $workActivity['at'] ?? null,
            'work_activity_relative' => isset($workActivity['at'])
                ? display_app_timeline_relative($workActivity['at'])
                : null,
            'open_work_count' => $openWorkCount,
            'activity' => $activity,
        ];
    }

    /**
     * @param  array<string, mixed>  $authority
     * @param  array<string, mixed>  $sessionSummary
     */
    private function unavailabilityLabel(array $authority, array $sessionSummary): string
    {
        $lastEndedReason = $sessionSummary['last_ended_reason'] ?? null;

        if ($lastEndedReason === WorkSessionEndReason::AwayTimeout->value) {
            return 'Session timed out';
        }

        if ($lastEndedReason === WorkSessionEndReason::ManualLogout->value) {
            return 'Logged out during shift';
        }

        $blockReasons = $authority['block_reasons'] ?? [];

        if (in_array('approved_leave', $blockReasons, true)) {
            return 'Approved leave today';
        }

        if (in_array('not_present', $blockReasons, true)) {
            return 'Not logged in';
        }

        if (in_array('availability_offline', $blockReasons, true)) {
            return 'Marked offline';
        }

        return 'Unavailable during shift';
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{
     *     manual_logout_count: int,
     *     timeout_count: int,
     *     last_logout_at: string|null,
     *     last_logout_relative: string|null,
     *     last_ended_reason: string|null
     * }>
     */
    private function todaySessionSummariesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $userKey = $this->teamMemberIdsCacheKey($userIds);

        if ($this->cachedTodaySessionSummaries !== null && $this->cachedTodaySessionsUserKey === $userKey) {
            return $this->cachedTodaySessionSummaries;
        }

        $sessionsByUser = $this->todaySessionsGroupedByUser($userIds);
        $summaries = [];

        foreach ($userIds as $userId) {
            $userSessions = $sessionsByUser
                ->get($userId, collect())
                ->filter(fn (WorkSession $session): bool => $session->logout_at !== null)
                ->sortByDesc(fn (WorkSession $session): int => $session->logout_at?->getTimestamp() ?? 0)
                ->values();

            $lastSession = $userSessions->first();
            $lastLogoutAt = $lastSession?->logout_at;

            $summaries[$userId] = [
                'manual_logout_count' => $userSessions
                    ->where('ended_reason', WorkSessionEndReason::ManualLogout)
                    ->count(),
                'timeout_count' => $userSessions
                    ->where('ended_reason', WorkSessionEndReason::AwayTimeout)
                    ->count(),
                'last_logout_at' => $lastLogoutAt?->toIso8601String(),
                'last_logout_relative' => $lastLogoutAt !== null
                    ? display_app_timeline_relative($lastLogoutAt)
                    : null,
                'last_ended_reason' => $lastSession?->ended_reason?->value,
            ];
        }

        $this->cachedTodaySessionSummaries = $summaries;

        return $summaries;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function openWorkSessionUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $sessionsByUser = $this->todaySessionsGroupedByUser($userIds);
        $openUserIds = [];

        foreach ($userIds as $userId) {
            $hasOpen = $sessionsByUser
                ->get($userId, collect())
                ->contains(function (WorkSession $session): bool {
                    if ($session->logout_at !== null) {
                        return false;
                    }

                    return $session->is_attributable === true || $session->is_attributable === null;
                });

            if ($hasOpen) {
                $openUserIds[] = $userId;
            }
        }

        return $openUserIds;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, WorkSession>>
     */
    private function todaySessionsGroupedByUser(array $userIds): Collection
    {
        $userKey = $this->teamMemberIdsCacheKey($userIds);

        if ($this->cachedTodaySessionsByUser !== null && $this->cachedTodaySessionsUserKey === $userKey) {
            return $this->cachedTodaySessionsByUser;
        }

        $this->cachedTodaySessionsUserKey = $userKey;
        $this->cachedTodaySessionsByUser = WorkSession::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', now()->toDateString())
            ->orderByDesc('logout_at')
            ->get()
            ->groupBy('user_id');
        $this->cachedTodaySessionSummaries = null;

        return $this->cachedTodaySessionsByUser;
    }

    /**
     * @param  list<int>  $userIds
     */
    private function teamMemberIdsCacheKey(array $userIds): string
    {
        $sorted = $userIds;
        sort($sorted);

        return implode(',', $sorted);
    }

    /**
     * @return array{
     *     manual_logout_count: int,
     *     timeout_count: int,
     *     last_logout_at: string|null,
     *     last_logout_relative: string|null,
     *     last_ended_reason: string|null
     * }
     */
    private function emptySessionSummary(): array
    {
        return [
            'manual_logout_count' => 0,
            'timeout_count' => 0,
            'last_logout_at' => null,
            'last_logout_relative' => null,
            'last_ended_reason' => null,
        ];
    }
}
