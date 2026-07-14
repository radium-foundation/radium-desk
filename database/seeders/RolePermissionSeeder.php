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

    public const ROLE_OPERATIONS_ADMIN = 'operations_admin';

    public const ROLE_SUPPORT_SPECIALIST = 'support_specialist';

    public const ROLE_CUSTOMER_COORDINATOR = 'customer_coordinator';

    public const ROLE_HARDWARE_TEAM = 'hardware_team';

    public const ROLE_ESCALATION_SPECIALIST = 'escalation_specialist';

    public const PERMISSION_CORRECT_ORDER_IDENTITY = 'orders.correct-identity';

    /**
     * @var list<string>
     */
    public const DIRECT_ASSIGNABLE_PERMISSIONS = [
        self::PERMISSION_CORRECT_ORDER_IDENTITY,
    ];

    /**
     * @var list<string>
     */
    public const SUPPORT_TEAM_ROLES = [
        self::ROLE_AGENT,
        self::ROLE_SUPPORT_SPECIALIST,
        self::ROLE_CUSTOMER_COORDINATOR,
    ];

    /**
     * @var list<string>
     */
    public const INQUIRY_ASSIGNMENT_ROLES = [
        self::ROLE_AGENT,
        self::ROLE_CUSTOMER_COORDINATOR,
    ];

    /**
     * @var list<string>
     */
    public const ADMIN_TEAM_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_OPERATIONS_ADMIN,
        self::ROLE_SUPERADMIN,
    ];

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
            'leave-requests.view',
            'leave-requests.create',
        ],
        self::ROLE_SUPPORT_SPECIALIST => [
            'orders.view',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'approvals.view',
            'refunds.view',
            'refunds.create',
            'leave-requests.view',
            'leave-requests.create',
        ],
        self::ROLE_CUSTOMER_COORDINATOR => [
            'orders.view',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'approvals.view',
            'refunds.view',
            'refunds.create',
            'leave-requests.view',
            'leave-requests.create',
        ],
        self::ROLE_ESCALATION_SPECIALIST => [
            'orders.view',
            'incidents.view',
            'incidents.create',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'approvals.view',
            'refunds.view',
            'refunds.create',
            'leave-requests.view',
            'leave-requests.create',
        ],
        self::ROLE_HARDWARE_TEAM => [
            'dashboard.hardware.view',
            'orders.view',
            'orders.update',
            'incidents.view',
            'incidents.update',
            'remarks.view',
            'remarks.create',
            'leave-requests.view',
            'leave-requests.create',
        ],
        self::ROLE_ADMIN => [
            'dashboard.hardware.view',
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
            'refunds.execute',
            'audit-logs.view',
            'automation-operations.view',
            'operations-dashboard.view',
            'system-settings.manage',
            'cashfree-webhook-logs.view',
            'users.view',
            'users.manage',
            'leave-requests.view',
            'leave-requests.create',
            'leave-requests.review',
            'workforce-calendar.manage',
            'team-performance.view',
        ],
        self::ROLE_OPERATIONS_ADMIN => [
            'dashboard.hardware.view',
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
            'refunds.execute',
            'audit-logs.view',
            'automation-operations.view',
            'operations-dashboard.view',
            'system-settings.manage',
            'cashfree-webhook-logs.view',
            'users.view',
            'users.manage',
            'leave-requests.view',
            'leave-requests.create',
            'leave-requests.review',
            'workforce-calendar.manage',
            'team-performance.view',
        ],
        self::ROLE_SUPERADMIN => [
            'dashboard.hardware.view',
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
            'refunds.execute',
            'refunds.delete',
            'audit-logs.view',
            'automation-operations.view',
            'operations-dashboard.view',
            'system-settings.manage',
            'cashfree-webhook-logs.view',
            'users.view',
            'users.manage',
            'leave-requests.view',
            'leave-requests.create',
            'leave-requests.review',
            'workforce-calendar.manage',
            'team-performance.view',
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

        foreach (self::DIRECT_ASSIGNABLE_PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($rolePermissions);
        }
    }
}
