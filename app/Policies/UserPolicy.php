<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
            && $user->id !== $model->id;
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    public function updateStatus(User $user, User $model): bool
    {
        return $this->canManage($user, $model);
    }

    private function canManage(User $actor, User $target): bool
    {
        if (! $actor->can('users.manage')) {
            return false;
        }

        if ($target->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)
            && ! $actor->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return false;
        }

        return true;
    }
}
