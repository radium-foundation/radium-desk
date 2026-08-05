<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * Shared Cashfree test bootstrap: pin system-user config and ensure the actor exists.
 *
 * phpunit.xml already forces CASHFREE_SYSTEM_USER_EMAIL; this trait keeps
 * feature suites deterministic when they create the automation actor.
 */
trait EnsuresCashfreeSystemUser
{
    protected function ensureCashfreeSystemUser(
        string $email = 'superadmin@radium.local',
        string $role = RolePermissionSeeder::ROLE_SUPERADMIN,
    ): User {
        config([
            'cashfree.system_user_email' => $email,
            'cashfree.verify_signature' => false,
        ]);

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $user = User::factory()->create([
                'email' => $email,
                'is_active' => true,
            ]);
        } else {
            $user->forceFill(['is_active' => true])->save();
        }

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }
}
