<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamAvailabilityOverviewService
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamMemberActivityService $activityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly WorkforceAuthorityService $workforceAuthority,
    ) {}

    /**
     * @return array{on_duty: list<array<string, mixed>>, unavailable: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        return [
            'on_duty' => $this->members(),
            'unavailable' => $this->unavailableMembers(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function members(): array
    {
        $snapshot = DashboardSnapshot::load();

        return $this->teamMembers()
            ->filter(fn (User $user): bool => $this->workforceAuthority->isOnDuty($user))
            ->map(fn (User $user): array => $this->memberRow($user, $snapshot->openCount($user)))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function unavailableMembers(): array
    {
        $snapshot = DashboardSnapshot::load();

        return $this->teamMembers()
            ->filter(fn (User $user): bool => $this->isExpectedUnavailable($user))
            ->map(fn (User $user): array => $this->unavailableMemberRow($user, $snapshot->openCount($user)))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function memberSnapshot(User $user): array
    {
        return $this->memberRow($user, DashboardSnapshot::load()->openCount($user));
    }

    private function isExpectedUnavailable(User $user): bool
    {
        return $this->workCalendarService->isOnScheduledShift($user)
            && ! $this->workforceAuthority->isOnDuty($user);
    }

    /**
     * @return Collection<int, User>
     */
    private function teamMembers(): Collection
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

    /**
     * @return array<string, mixed>
     */
    private function memberRow(User $user, int $openWorkCount): array
    {
        $authority = $this->workforceAuthority->snapshotFor($user);
        $storedAvailability = $this->availabilityService->snapshotFor($user);
        $effectiveStatus = TeamAvailabilityStatus::from($authority['effective_availability']);
        $workCalendar = $this->workCalendarService->todayStatusFor($user);
        $presence = $this->presenceEngine->snapshotFor($user);
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
     * @return array<string, mixed>
     */
    private function unavailableMemberRow(User $user, int $openWorkCount): array
    {
        $row = $this->memberRow($user, $openWorkCount);
        $sessionSummary = $this->todaySessionSummary($user);

        return [
            ...$row,
            'unavailability_label' => $this->unavailabilityLabel($row['authority'], $sessionSummary),
            'session_summary' => $sessionSummary,
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
     * @return array{
     *     manual_logout_count: int,
     *     timeout_count: int,
     *     last_logout_at: string|null,
     *     last_logout_relative: string|null,
     *     last_ended_reason: string|null
     * }
     */
    private function todaySessionSummary(User $user): array
    {
        $sessions = WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', now()->toDateString())
            ->whereNotNull('logout_at')
            ->orderByDesc('logout_at')
            ->get();

        $lastSession = $sessions->first();
        $lastLogoutAt = $lastSession?->logout_at;

        return [
            'manual_logout_count' => $sessions
                ->where('ended_reason', WorkSessionEndReason::ManualLogout)
                ->count(),
            'timeout_count' => $sessions
                ->where('ended_reason', WorkSessionEndReason::AwayTimeout)
                ->count(),
            'last_logout_at' => $lastLogoutAt?->toIso8601String(),
            'last_logout_relative' => $lastLogoutAt !== null
                ? display_app_timeline_relative($lastLogoutAt)
                : null,
            'last_ended_reason' => $lastSession?->ended_reason?->value,
        ];
    }
}
