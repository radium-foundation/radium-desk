<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class TeamActivityPolicy
{
    /**
     * Team Activity is a workforce team-visibility surface.
     *
     * Authorization uses the dedicated {@see RolePermissionSeeder::PERMISSION_TEAM_ACTIVITY_VIEW}
     * permission. Seed assignment derives that permission for any role with
     * {@see RolePermissionSeeder::PERMISSION_WORKFORCE_VIEW}.
     */
    public function view(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_TEAM_ACTIVITY_VIEW);
    }
}
