<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\User;
use App\Models\WorkSession;
use App\ReadModels\Cases\CaseQueueReadModel;
use Illuminate\Support\Collection;

class TeamAvailabilityOverviewService
{
    /** @var Collection<int, User>|null */
    private ?Collection $cachedTeamMembers = null;

    /** @var array{on_duty: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}|null */
    private ?array $cachedOverview = null;

    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamMemberActivityService $activityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly WorkforceAuthorityService $workforceAuthority,
        private readonly CaseQueueReadModel $caseQueue,
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
        $sessionSummaries = $this->todaySessionSummariesFor(
            $teamMembers->map(fn (User $user): int => $user->id)->all()
        );

        $onDuty = [];
        $unavailable = [];

        foreach ($teamMembers as $user) {
            $openCount = $openCounts[$user->id] ?? 0;
            $row = $this->memberRow($user, $openCount);

            if ($row['on_duty'] === true) {
                $onDuty[] = $row;

                continue;
            }

            if ($this->workCalendarService->isOnScheduledShift($user)) {
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
    private function memberRow(User $user, int $openWorkCount): array
    {
        $authority = $this->workforceAuthority->snapshotFor($user);
        $storedAvailability = $authority['availability'] ?? $this->availabilityService->snapshotFor($user);
        $effectiveStatus = TeamAvailabilityStatus::from($authority['effective_availability']);
        $workCalendar = $authority['work_calendar'] ?? $this->workCalendarService->todayStatusFor($user);
        $presence = $authority['presence'] ?? $this->presenceEngine->snapshotFor($user);
        $activity = $this->activityService->snapshotFor($user);
        $workActivity = $this->activityService->primaryWorkActivity($user);

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

        $sessions = WorkSession::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', now()->toDateString())
            ->whereNotNull('logout_at')
            ->orderByDesc('logout_at')
            ->get()
            ->groupBy('user_id');

        $summaries = [];

        foreach ($userIds as $userId) {
            $userSessions = $sessions->get($userId, collect());
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

        return $summaries;
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
