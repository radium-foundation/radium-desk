<?php

namespace App\Services\Operations;

use App\Enums\OperationsKpiProfile;
use App\Models\User;

class OperationsKpiProfileResolver
{
    public function __construct(
        private readonly OperationsRoleService $roleService,
    ) {}

    public function resolve(User $user): OperationsKpiProfile
    {
        if ($this->roleService->usesAdminQueues($user)) {
            return OperationsKpiProfile::Activation;
        }

        return OperationsKpiProfile::Support;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, OperationsKpiProfile>
     */
    public function resolveForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $users = User::query()
            ->with('roles')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $profiles = [];

        foreach ($userIds as $userId) {
            $user = $users->get($userId);

            if (! $user instanceof User) {
                continue;
            }

            $profiles[$userId] = $this->resolve($user);
        }

        return $profiles;
    }
}
