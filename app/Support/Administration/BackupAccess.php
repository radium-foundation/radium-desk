<?php

namespace App\Support\Administration;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class BackupAccess
{
    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_BACKUPS_VIEW);
    }
}
