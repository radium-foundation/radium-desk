<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * C360 Central Wallet tab uses finance.wallet.view (display-only; not finance.view).
 * Agent already had this; escalation/support/coordinator roles did not.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ROLES = [
        RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
        RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
        RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW, 'web');

        foreach (self::ROLES as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($role === null) {
                continue;
            }

            if (! $role->hasPermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW)) {
                $role->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLES as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'web')
                ->first();

            if ($role === null) {
                continue;
            }

            if ($role->hasPermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW)) {
                $role->revokePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW);
            }
        }
    }
};
