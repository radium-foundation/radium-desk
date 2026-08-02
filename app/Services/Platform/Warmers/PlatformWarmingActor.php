<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves a warming actor without requiring a browser session.
 *
 * Preference: active Superadmin → any active user → synthetic in-memory user.
 * Synthetic users skip permission gates during warm (zone HTML is still RBAC-filtered on read).
 */
final class PlatformWarmingActor
{
    public const SYNTHETIC_ID = 0;

    public static function resolve(): User
    {
        if (Schema::hasTable('users')) {
            $superadmin = User::query()
                ->where('is_active', true)
                ->whereHas('roles', static fn ($query) => $query->where('name', RolePermissionSeeder::ROLE_SUPERADMIN))
                ->orderBy('id')
                ->first();

            if ($superadmin !== null) {
                return $superadmin;
            }

            $any = User::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if ($any !== null) {
                return $any;
            }
        }

        return self::synthetic();
    }

    public static function synthetic(): User
    {
        $user = new User([
            'name' => 'Platform Snapshot Warmer',
            'email' => 'platform-warmer@internal.local',
            'is_active' => true,
        ]);
        $user->id = self::SYNTHETIC_ID;
        $user->exists = false;

        return $user;
    }

    public static function isSynthetic(?User $user): bool
    {
        return $user !== null && (int) $user->getKey() === self::SYNTHETIC_ID && $user->exists === false;
    }
}
