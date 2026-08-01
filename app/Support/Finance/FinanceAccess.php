<?php

namespace App\Support\Finance;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Gate helpers for the Finance module hub and workspace tabs.
 *
 * Phase 1 is view-only placeholders. Keep checks on Spatie permissions so
 * create/review/post can layer on later without changing nav structure.
 */
final class FinanceAccess
{
    public static function allows(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->can(RolePermissionSeeder::PERMISSION_FINANCE_VIEW);
    }

    public static function allowsPermission(?User $user, string $permission): bool
    {
        if (! self::allows($user)) {
            return false;
        }

        return $user->can($permission);
    }
}
