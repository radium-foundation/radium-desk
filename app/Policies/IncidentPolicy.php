<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('incidents.view');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('incidents.view');
    }

    public function create(User $user): bool
    {
        return $user->can('incidents.create');
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->can('incidents.update');
    }

    public function delete(User $user, Incident $incident): bool
    {
        return $user->can('incidents.delete');
    }

    public function reassign(User $user, Incident $incident): bool
    {
        return $user->hasAnyRole([
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }
}
