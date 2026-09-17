<?php

namespace App\Support\Purchasing;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

final class PurchasingAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_PURCHASE_VIEW);
    }

    public static function allowsPermission(?User $user, string $permission): bool
    {
        if (! self::allows($user)) {
            return false;
        }

        return $user->can($permission);
    }
}
