<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
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
     * @return array<string, mixed>
     */
    public function memberSnapshot(User $user): array
    {
        return $this->memberRow($user, DashboardSnapshot::load()->openCount($user));
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
}
