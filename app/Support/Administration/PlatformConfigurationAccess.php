<?php

namespace App\Support\Administration;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Single authorization point for Platform Configuration visibility and access.
 *
 * Operational Settings remain available to users with system-settings.manage.
 * Platform Configuration (integrations health overview, environment, advanced
 * platform controls, audit drawer, platform links) is Super Admin only.
 */
final class PlatformConfigurationAccess
{
    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }
}
