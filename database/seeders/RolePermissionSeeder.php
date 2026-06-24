<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_AGENT = 'agent';

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSIONS = [
        self::ROLE_AGENT => [
            'orders.view',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'approvals.view',
            'refunds.view',
            'refunds.create',
        ],
        self::ROLE_ADMIN => [
            'orders.view',
            'orders.create',
            'orders.update',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'approvals.view',
            'approvals.create',
            'approvals.link',
            'refunds.view',
            'refunds.create',
            'refunds.review',
            'audit-logs.view',
            'users.view',
            'users.manage',
        ],
        self::ROLE_SUPERADMIN => [
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.delete',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'incidents.delete',
            'remarks.view',
            'remarks.create',
            'remarks.delete',
            'approvals.view',
            'approvals.create',
            'approvals.link',
            'approvals.delete',
            'refunds.view',
            'refunds.create',
            'refunds.review',
            'refunds.delete',
            'audit-logs.view',
            'users.view',
            'users.manage',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->unique()
            ->values();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }
    }
}
