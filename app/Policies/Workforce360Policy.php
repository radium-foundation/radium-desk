<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Operations\OperationsRoleService;

class Workforce360Policy
{
    public function __construct(
        private readonly OperationsRoleService $roleService,
    ) {}

    public function viewTeam(User $user): bool
    {
        return $user->can('workforce.view');
    }

    public function viewMember(User $user, User $member): bool
    {
        if ($user->id === $member->id) {
            return $user->can('workforce.self');
        }

        if (! $user->can('workforce.view.member')) {
            return false;
        }

        return $member->is_active
            && $this->roleService->isAttendanceTracked($member);
    }

    public function viewSelf(User $user): bool
    {
        return $user->can('workforce.self')
            && $this->roleService->isAttendanceTracked($user);
    }
}
