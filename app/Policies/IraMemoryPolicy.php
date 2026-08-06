<?php

namespace App\Policies;

use App\Models\IraMemory;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Dedicated policy hook for IRA Memory admin.
 * Initially mirrors Learning Center gate (SystemSetting update + inbound email).
 */
class IraMemoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canManageMemory($user);
    }

    public function view(User $user, IraMemory $memory): bool
    {
        return $this->canManageMemory($user);
    }

    public function update(User $user, IraMemory $memory): bool
    {
        return $this->canManageMemory($user);
    }

    public function delete(User $user, IraMemory $memory): bool
    {
        return $this->canManageMemory($user);
    }

    private function canManageMemory(User $user): bool
    {
        if (! config('inbound_email.enabled')) {
            return false;
        }

        return $user->can('update', SystemSetting::class);
    }
}
