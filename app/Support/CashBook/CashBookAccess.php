<?php

namespace App\Support\CashBook;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class CashBookAccess
{
    public static function allowsView(?User $user): bool
    {
        return $user !== null && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW);
    }

    public static function allowsCreate(?User $user): bool
    {
        return $user !== null
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_CREATE);
    }

    public static function allowsManage(?User $user): bool
    {
        return $user !== null
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)
            && $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_MANAGE);
    }
}
