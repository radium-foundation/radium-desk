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

    public const ROLE_EMPLOYEE = 'employee';

    public const PERMISSION_CORRECT_ORDER_IDENTITY = 'orders.correct-identity';

    public const PERMISSION_TEAM_ACTIVITY_VIEW = 'team-activity.view';

    public const PERMISSION_WORKFORCE_VIEW = 'workforce.view';

    /**
     * @var list<string>
     */
    public const DIRECT_ASSIGNABLE_PERMISSIONS = [
        self::PERMISSION_CORRECT_ORDER_IDENTITY,
    ];

    /**
     * Permissions granted alongside workforce team visibility.
     *
     * Any role that receives {@see self::PERMISSION_WORKFORCE_VIEW} inherits these
     * automatically so new operational roles do not need a separate Team Activity grant.
     *
     * @var list<string>
     */
    private const WORKFORCE_TEAM_VISIBILITY_PERMISSIONS = [
        self::PERMISSION_TEAM_ACTIVITY_VIEW,
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
            'workforce.view',
            'workforce.self',
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
            'workforce.view',
            'workforce.self',
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
            'workforce.view',
            'workforce.self',
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
            'workforce.view',
            'workforce.self',
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
            'workforce.view',
            'workforce.self',
        ],
        // Non-support staff: own attendance + leave only (profile/notifications are auth-gated).
        self::ROLE_EMPLOYEE => [
            'leave-requests.view',
            'leave-requests.create',
            'workforce.self',
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
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
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
            'platform-dashboard.view',
            'system-settings.manage',
            'cashfree-webhook-logs.view',
            'users.view',
            'users.manage',
            'leave-requests.view',
            'leave-requests.create',
            'leave-requests.review',
            'workforce-calendar.manage',
            'team-performance.view',
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
            'workforce.recognition.review',
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
            'platform-dashboard.view',
            'system-settings.manage',
            'cashfree-webhook-logs.view',
            'users.view',
            'users.manage',
            'leave-requests.view',
            'leave-requests.create',
            'leave-requests.review',
            'workforce-calendar.manage',
            'team-performance.view',
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
            'workforce.recognition.review',
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->merge(self::DIRECT_ASSIGNABLE_PERMISSIONS)
            ->merge(self::WORKFORCE_TEAM_VISIBILITY_PERMISSIONS)
            ->unique()
            ->values();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLE_PERMISSIONS as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($this->permissionsForRole($rolePermissions));
        }
    }

    /**
     * @param  list<string>  $rolePermissions
     * @return list<string>
     */
    private function permissionsForRole(array $rolePermissions): array
    {
        if (! in_array(self::PERMISSION_WORKFORCE_VIEW, $rolePermissions, true)) {
            return $rolePermissions;
        }

        return array_values(array_unique([
            ...$rolePermissions,
            ...self::WORKFORCE_TEAM_VISIBILITY_PERMISSIONS,
        ]));
    }
}
