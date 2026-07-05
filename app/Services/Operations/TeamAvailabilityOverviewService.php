<?php

namespace App\Services\Operations;

use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use Illuminate\Support\Collection;

class TeamAvailabilityOverviewService
{
    public function __construct(
        private readonly TeamAvailabilityService $availabilityService,
        private readonly TeamMemberActivityService $activityService,
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function members(): array
    {
        $users = $this->teamMembers();
        $snapshot = DashboardSnapshot::load();

        return $users
            ->map(fn (User $user): array => $this->memberRow($user, $snapshot->openCount($user)))
            ->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function teamMembers(): Collection
    {
        return User::query()
            ->with('roles')
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(User $user, int $openWorkCount): array
    {
        $availability = $this->availabilityService->snapshotFor($user);
        $activity = $this->activityService->snapshotFor($user);
        $workActivity = $this->activityService->primaryWorkActivity($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role_label' => $user->primaryRoleLabel(),
            'availability' => $availability,
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
