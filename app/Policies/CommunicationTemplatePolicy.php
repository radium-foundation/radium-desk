<?php

namespace App\Policies;

use App\Models\CommunicationTemplate;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class CommunicationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_COMMUNICATION_TEMPLATES_VIEW);
    }

    public function view(User $user, CommunicationTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_COMMUNICATION_TEMPLATES_MANAGE);
    }

    public function update(User $user, CommunicationTemplate $template): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_COMMUNICATION_TEMPLATES_MANAGE);
    }

    public function manage(User $user): bool
    {
        return $user->can(RolePermissionSeeder::PERMISSION_COMMUNICATION_TEMPLATES_MANAGE);
    }
}
