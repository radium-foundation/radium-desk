<?php

namespace App\Support\Administration;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Super Admin only — Phase 0 shadow Performance Intelligence.
 *
 * Never grant to agents, team leads, or ordinary admins.
 */
final class PerformanceIntelligenceAccess
{
    public static function canView(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! (bool) config('performance_intelligence.enabled', false)) {
            return false;
        }

        return $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }
}
