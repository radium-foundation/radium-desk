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

    public const PERMISSION_WORKFORCE_PAYROLL_MANAGE = 'workforce.payroll.manage';

    /**
     * Future: reopen a finalized payroll month. Super Admin only.
     */
    public const PERMISSION_WORKFORCE_PAYROLL_REOPEN = 'workforce.payroll.reopen';

    /** Short Attendance Phase 2 — HR review queue (view). */
    public const PERMISSION_SHORT_ATTENDANCE_VIEW = 'workforce.short_attendance.view';

    /** Short Attendance Phase 2 — HR review decisions (Shipra / Ops Admin). */
    public const PERMISSION_SHORT_ATTENDANCE_REVIEW = 'workforce.short_attendance.review';

    public const PERMISSION_FINANCE_VIEW = 'finance.view';

    public const PERMISSION_FINANCE_DASHBOARD_VIEW = 'finance.dashboard.view';

    public const PERMISSION_FINANCE_PAYMENTS_VIEW = 'finance.payments.view';

    public const PERMISSION_FINANCE_EXPENSES_VIEW = 'finance.expenses.view';

    public const PERMISSION_FINANCE_CASH_VIEW = 'finance.cash.view';

    public const PERMISSION_FINANCE_CLOSINGS_VIEW = 'finance.closings.view';

    public const PERMISSION_FINANCE_BANK_VIEW = 'finance.bank.view';

    public const PERMISSION_FINANCE_VENDOR_PAYMENTS_VIEW = 'finance.vendor-payments.view';

    public const PERMISSION_FINANCE_SETTINGS_VIEW = 'finance.settings.view';

    public const PERMISSION_FINANCE_INVOICES_VIEW = 'finance.invoices.view';

    public const PERMISSION_FINANCE_INVOICES_ISSUE = 'finance.invoices.issue';

    public const PERMISSION_CASHBOOK_VIEW = 'cashbook.view';

    public const PERMISSION_CASHBOOK_CREATE = 'cashbook.create';

    public const PERMISSION_CASHBOOK_MANAGE = 'cashbook.manage';

    public const PERMISSION_CASHBOOK_HISTORICAL = 'cashbook.historical';

    public const PERMISSION_EMAIL_REPLY = 'email.reply';

    public const PERMISSION_EMAIL_INTAKE_VIEW = 'email-intake.view';

    public const PERMISSION_EMAIL_INTAKE_MANAGE = 'email-intake.manage';

    /** Record Finance-verified external wallet reverse → restore commercial service. */
    public const PERMISSION_COMMERCIAL_SERVICE_RESTORE = 'commercial.service.restore';

    /** Read-only backup status in Administration (Super Admin only). */
    public const PERMISSION_BACKUPS_VIEW = 'backups.view';

    public const PERMISSION_INVENTORY_VIEW = 'inventory.view';

    public const PERMISSION_INVENTORY_PRODUCTS_MANAGE = 'inventory.products.manage';

    public const PERMISSION_INVENTORY_BRANCHES_MANAGE = 'inventory.branches.manage';

    public const PERMISSION_INVENTORY_STOCK_IN = 'inventory.stock.in';

    public const PERMISSION_INVENTORY_STOCK_TRANSFER = 'inventory.stock.transfer';

    public const PERMISSION_INVENTORY_STOCK_ADJUST = 'inventory.stock.adjust';

    public const PERMISSION_INVENTORY_STOCK_RESERVE = 'inventory.stock.reserve';

    public const PERMISSION_INVENTORY_OPENING_IMPORT = 'inventory.opening.import';

    public const PERMISSION_POS_VIEW = 'pos.view';

    public const PERMISSION_POS_SELL = 'pos.sell';

    public const PERMISSION_POS_CANCEL = 'pos.cancel';

    /** Confirm a pending UPI POS intent after checking the live bank. Assigned to no role. */
    public const PERMISSION_POS_PAYMENTS_VERIFY = 'pos.payments.verify';

    /** Operate stock and POS at every branch without a per-branch assignment. */
    public const PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES = 'inventory.branches.operate-all';

    public const PERMISSION_TODOS_VIEW = 'todos.view';

    public const PERMISSION_TODOS_CREATE = 'todos.create';

    public const PERMISSION_TODOS_UPDATE = 'todos.update';

    public const PERMISSION_TODOS_ASSIGN = 'todos.assign';

    public const PERMISSION_TODOS_MANAGE = 'todos.manage';

    /**
     * Baseline To-Do access for roles that already create leave requests.
     *
     * @var list<string>
     */
    private const TODO_BASELINE_PERMISSIONS = [
        self::PERMISSION_TODOS_VIEW,
        self::PERMISSION_TODOS_CREATE,
        self::PERMISSION_TODOS_UPDATE,
    ];

    /**
     * Inventory + POS action permissions for admin-team roles.
     *
     * @var list<string>
     */
    private const INVENTORY_ADMIN_PERMISSIONS = [
        self::PERMISSION_INVENTORY_VIEW,
        self::PERMISSION_INVENTORY_PRODUCTS_MANAGE,
        self::PERMISSION_INVENTORY_BRANCHES_MANAGE,
        self::PERMISSION_INVENTORY_STOCK_IN,
        self::PERMISSION_INVENTORY_STOCK_TRANSFER,
        self::PERMISSION_INVENTORY_STOCK_ADJUST,
        self::PERMISSION_INVENTORY_STOCK_RESERVE,
        self::PERMISSION_INVENTORY_OPENING_IMPORT,
        self::PERMISSION_POS_VIEW,
        self::PERMISSION_POS_SELL,
        self::PERMISSION_POS_CANCEL,
        self::PERMISSION_INVENTORY_OPERATE_ALL_BRANCHES,
    ];

    /**
     * Counter + warehouse operations for hardware team.
     *
     * @var list<string>
     */
    private const INVENTORY_HARDWARE_PERMISSIONS = [
        self::PERMISSION_INVENTORY_VIEW,
        self::PERMISSION_INVENTORY_STOCK_IN,
        self::PERMISSION_INVENTORY_STOCK_TRANSFER,
        self::PERMISSION_INVENTORY_STOCK_RESERVE,
        self::PERMISSION_POS_VIEW,
        self::PERMISSION_POS_SELL,
    ];

    /**
     * Cross-user assign/manage for admin team roles.
     *
     * @var list<string>
     */
    private const TODO_ADMIN_PERMISSIONS = [
        self::PERMISSION_TODOS_ASSIGN,
        self::PERMISSION_TODOS_MANAGE,
    ];

    /**
     * @var list<string>
     */
    public const DIRECT_ASSIGNABLE_PERMISSIONS = [
        self::PERMISSION_CORRECT_ORDER_IDENTITY,
        self::PERMISSION_CASHBOOK_VIEW,
        self::PERMISSION_CASHBOOK_CREATE,
        self::PERMISSION_CASHBOOK_MANAGE,
        self::PERMISSION_CASHBOOK_HISTORICAL,
        self::PERMISSION_EMAIL_REPLY,
        self::PERMISSION_EMAIL_INTAKE_VIEW,
        self::PERMISSION_EMAIL_INTAKE_MANAGE,
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
     * Finance Phase 1 view permissions. Granted with {@see self::PERMISSION_FINANCE_VIEW}.
     *
     * @var list<string>
     */
    private const FINANCE_MODULE_VIEW_PERMISSIONS = [
        self::PERMISSION_FINANCE_DASHBOARD_VIEW,
        self::PERMISSION_FINANCE_PAYMENTS_VIEW,
        self::PERMISSION_FINANCE_EXPENSES_VIEW,
        self::PERMISSION_FINANCE_CASH_VIEW,
        self::PERMISSION_FINANCE_CLOSINGS_VIEW,
        self::PERMISSION_FINANCE_BANK_VIEW,
        self::PERMISSION_FINANCE_VENDOR_PAYMENTS_VIEW,
        self::PERMISSION_FINANCE_SETTINGS_VIEW,
        self::PERMISSION_FINANCE_INVOICES_VIEW,
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
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
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
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
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
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
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
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
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
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            ...self::INVENTORY_HARDWARE_PERMISSIONS,
        ],
        // Non-support staff: own attendance + leave only (profile/notifications are auth-gated).
        self::ROLE_EMPLOYEE => [
            'leave-requests.view',
            'leave-requests.create',
            'workforce.self',
            ...self::TODO_BASELINE_PERMISSIONS,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
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
            'leave-requests.manage',
            'workforce-calendar.manage',
            'team-performance.view',
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
            ...self::TODO_BASELINE_PERMISSIONS,
            ...self::TODO_ADMIN_PERMISSIONS,
            self::PERMISSION_SHORT_ATTENDANCE_VIEW,
            self::PERMISSION_SHORT_ATTENDANCE_REVIEW,
            self::PERMISSION_FINANCE_VIEW,
            self::PERMISSION_FINANCE_INVOICES_ISSUE,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_CASHBOOK_MANAGE,
            self::PERMISSION_EMAIL_REPLY,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
            self::PERMISSION_COMMERCIAL_SERVICE_RESTORE,
            ...self::INVENTORY_ADMIN_PERMISSIONS,
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
            'leave-requests.manage',
            'workforce-calendar.manage',
            'team-performance.view',
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
            'workforce.recognition.review',
            ...self::TODO_BASELINE_PERMISSIONS,
            ...self::TODO_ADMIN_PERMISSIONS,
            self::PERMISSION_SHORT_ATTENDANCE_VIEW,
            self::PERMISSION_SHORT_ATTENDANCE_REVIEW,
            self::PERMISSION_WORKFORCE_PAYROLL_MANAGE,
            self::PERMISSION_FINANCE_VIEW,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_EMAIL_REPLY,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
            self::PERMISSION_COMMERCIAL_SERVICE_RESTORE,
            ...self::INVENTORY_ADMIN_PERMISSIONS,
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
            'leave-requests.manage',
            'workforce-calendar.manage',
            'team-performance.view',
            'workforce.view',
            'workforce.view.member',
            'workforce.self',
            'workforce.recognition.view',
            'workforce.recognition.review',
            ...self::TODO_BASELINE_PERMISSIONS,
            ...self::TODO_ADMIN_PERMISSIONS,
            self::PERMISSION_SHORT_ATTENDANCE_VIEW,
            self::PERMISSION_SHORT_ATTENDANCE_REVIEW,
            self::PERMISSION_WORKFORCE_PAYROLL_MANAGE,
            self::PERMISSION_WORKFORCE_PAYROLL_REOPEN,
            self::PERMISSION_FINANCE_VIEW,
            self::PERMISSION_FINANCE_INVOICES_ISSUE,
            self::PERMISSION_CASHBOOK_VIEW,
            self::PERMISSION_CASHBOOK_CREATE,
            self::PERMISSION_CASHBOOK_MANAGE,
            self::PERMISSION_CASHBOOK_HISTORICAL,
            self::PERMISSION_EMAIL_REPLY,
            self::PERMISSION_EMAIL_INTAKE_VIEW,
            self::PERMISSION_EMAIL_INTAKE_MANAGE,
            self::PERMISSION_COMMERCIAL_SERVICE_RESTORE,
            self::PERMISSION_BACKUPS_VIEW,
            ...self::INVENTORY_ADMIN_PERMISSIONS,
        ],
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = collect(self::ROLE_PERMISSIONS)
            ->flatten()
            ->merge(self::DIRECT_ASSIGNABLE_PERMISSIONS)
            ->merge(self::WORKFORCE_TEAM_VISIBILITY_PERMISSIONS)
            ->merge(self::FINANCE_MODULE_VIEW_PERMISSIONS)
            ->merge(self::TODO_BASELINE_PERMISSIONS)
            ->merge(self::TODO_ADMIN_PERMISSIONS)
            ->merge([self::PERMISSION_POS_PAYMENTS_VERIFY])
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
        $expanded = $rolePermissions;

        if (in_array(self::PERMISSION_WORKFORCE_VIEW, $rolePermissions, true)) {
            $expanded = [
                ...$expanded,
                ...self::WORKFORCE_TEAM_VISIBILITY_PERMISSIONS,
            ];
        }

        if (in_array(self::PERMISSION_FINANCE_VIEW, $rolePermissions, true)) {
            $expanded = [
                ...$expanded,
                ...self::FINANCE_MODULE_VIEW_PERMISSIONS,
            ];
        }

        return array_values(array_unique($expanded));
    }
}
