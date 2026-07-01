<?php

namespace App\Policies;

use App\Models\User;

class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('system-settings.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('system-settings.manage');
    }
}
