<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function update(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }
}
