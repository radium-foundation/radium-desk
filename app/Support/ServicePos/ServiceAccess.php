<?php

namespace App\Support\ServicePos;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class ServiceAccess
{
    public static function allowsView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_SERVICES_VIEW);
    }

    public static function allowsManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_SERVICES_MANAGE);
    }

    public static function allowsSell(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_SERVICE_POS_SELL);
    }
}
