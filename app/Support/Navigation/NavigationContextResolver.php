<?php

namespace App\Support\Navigation;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\CompanyHoliday;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SystemSetting;
use App\Models\Todo;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Support\Finance\FinanceAccess;
use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
use App\Support\IncomingEmail\IncomingEmailAccess;
use App\Support\Inventory\InventoryAccess;
use App\Support\Inventory\PosAccess;
use App\Support\Purchasing\PurchasingAccess;
use App\Support\ServicePos\ServiceAccess;
use App\Support\Workforce\AttendanceManagementAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class NavigationContextResolver
{
    public function resolve(Request $request, string $pageTitle): NavigationContext
    {
        [$menu, $activeItemKey, $breadcrumbSuffix] = $this->resolveRouteContext($request);
        $menuHomeUrl = $this->resolveMenuHomeUrl($request, $menu);

        $menuLabel = $menu?->label();
        $documentTitle = $menuLabel !== null
            ? $menuLabel.' · '.$pageTitle
            : $pageTitle;

        $breadcrumbs = $this->buildBreadcrumbs($menu, $pageTitle, $breadcrumbSuffix, $menuHomeUrl);

        return new NavigationContext(
            menu: $menu,
            activeItemKey: $activeItemKey,
            pageTitle: $pageTitle,
            documentTitle: $documentTitle,
            breadcrumbs: $breadcrumbs,
            showBreadcrumb: $this->shouldShowBreadcrumb($request),
            resolvedMenuHomeUrl: $menuHomeUrl,
        );
    }

    /**
     * @return array{
     *     home: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     customers_and_service: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     sales_and_purchasing: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     inventory: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     finance: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     workforce: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     control_and_admin: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     * }
     */
    public function sidebar(Request $request, NavigationContext $context): array
    {
        $user = $request->user();
        $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

        $homeItems = [
            $this->sidebarItem('home.dashboard', 'Dashboard', 'bi-speedometer2', route('dashboard'), $context),
        ];

        $customersAndServiceItems = array_values(array_filter([
            $this->sidebarItem(
                'customers_and_service.service_desk',
                'Service Desk',
                'bi-headset',
                route('dashboard'),
                $context,
            ),
            Gate::check('viewAny', Incident::class)
                ? $this->sidebarItem(
                    'customers_and_service.service_cases',
                    'Service Cases',
                    'bi-ticket-detailed',
                    route('incidents.index'),
                    $context,
                )
                : null,
            Gate::check('viewAny', Order::class)
                ? $this->sidebarItem(
                    'customers_and_service.orders',
                    'Orders',
                    'bi-bag-check',
                    route('orders.index'),
                    $context,
                )
                : null,
            Gate::check('viewAny', RefundRequest::class)
                ? $this->sidebarItem(
                    'customers_and_service.refunds',
                    'Refunds',
                    'bi-arrow-counterclockwise',
                    route('refunds.index'),
                    $context,
                )
                : null,
        ]));

        $salesAndPurchasingItems = array_values(array_filter([
            PosAccess::allows($user)
                ? $this->sidebarItem(
                    'sales_and_purchasing.sell_products',
                    'Sell Products',
                    'bi-cart-check',
                    route('pos.counter.create'),
                    $context,
                )
                : null,
            ServiceAccess::allowsSell($user)
                ? $this->sidebarItem(
                    'sales_and_purchasing.sell_services',
                    'Sell Services',
                    'bi-briefcase',
                    route('service-pos.counter.create'),
                    $context,
                )
                : null,
            PurchasingAccess::allows($user)
                ? $this->sidebarItem(
                    'sales_and_purchasing.buy_products',
                    'Buy Products',
                    'bi-truck',
                    route('purchasing.purchase-orders.index'),
                    $context,
                )
                : null,
            PosAccess::allows($user)
                ? $this->sidebarItem(
                    'sales_and_purchasing.product_sales',
                    'Product Sales',
                    'bi-receipt',
                    route('pos.sales.index'),
                    $context,
                )
                : null,
        ]));

        $inventoryItems = InventoryAccess::allows($user)
            ? array_values(array_filter([
                $this->sidebarItem(
                    'inventory.stock',
                    'Stock',
                    'bi-box-seam',
                    route('inventory.stock.index'),
                    $context,
                ),
                $this->sidebarItem(
                    'inventory.serials',
                    'Serials',
                    'bi-upc-scan',
                    route('inventory.serials.index'),
                    $context,
                ),
                HardwareFulfilmentAccess::allows($user)
                    ? $this->sidebarItem(
                        'inventory.hardware',
                        'Hardware',
                        'bi-cpu',
                        route('inventory.hardware-fulfilments.index'),
                        $context,
                    )
                    : null,
                $this->sidebarItem(
                    'inventory.transfers',
                    'Transfers',
                    'bi-arrow-left-right',
                    route('inventory.transfers.index'),
                    $context,
                ),
                InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE)
                    ? $this->sidebarItem(
                        'inventory.products',
                        'Products',
                        'bi-tags',
                        route('inventory.products.index'),
                        $context,
                    )
                    : null,
                InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_BRANCHES_MANAGE)
                    ? $this->sidebarItem(
                        'inventory.branches',
                        'Branches',
                        'bi-geo-alt',
                        route('inventory.branches.index'),
                        $context,
                    )
                    : null,
                $this->sidebarItem(
                    'inventory.stock_history',
                    'Stock History',
                    'bi-clock-history',
                    route('inventory.movements.index'),
                    $context,
                ),
            ]))
            : [];

        $financeItems = array_values(array_filter([
            FinanceAccess::allows($user)
                ? $this->sidebarItem(
                    'finance.dashboard',
                    'Finance',
                    'bi-wallet2',
                    route('finance.dashboard'),
                    $context,
                )
                : null,
            ($user?->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW) ?? false)
                ? $this->sidebarItem(
                    'finance.cash_book',
                    'Cash Book',
                    'bi-journal-text',
                    route('cash-book.index'),
                    $context,
                )
                : null,
        ]));

        $workforceItems = array_values(array_filter([
            ($isAdminTeam && AttendanceManagementAccess::allows($user))
                ? $this->sidebarItem(
                    'workforce.attendance',
                    'Attendance',
                    'bi-calendar2-check',
                    route('workforce-management.attendance.index'),
                    $context,
                )
                : null,
            ($isAdminTeam && config('workforce_recognition.enabled') && $user?->can('workforce.recognition.view'))
                ? $this->sidebarItem(
                    'workforce.recognition',
                    'Work Recognition',
                    'bi-award',
                    route('workforce-management.recognition.index'),
                    $context,
                )
                : null,
            $user?->can('workforce360.viewSelf')
                ? $this->sidebarItem(
                    'workforce.my_workforce',
                    'My Workforce',
                    'bi-person-workspace',
                    route('my-workforce.index'),
                    $context,
                )
                : null,
            $this->canViewMyPerformance($user)
                ? $this->sidebarItem(
                    'workforce.my_performance',
                    'My Performance',
                    'bi-bar-chart',
                    route('my-performance.index'),
                    $context,
                )
                : null,
            Gate::check('viewAny', LeaveRequest::class)
                ? $this->sidebarItem(
                    'workforce.leave',
                    'Leave',
                    'bi-calendar-x',
                    route('leave-requests.index'),
                    $context,
                )
                : null,
            Gate::check('viewAny', Todo::class)
                ? array_merge(
                    $this->sidebarItem(
                        'workforce.todos',
                        'To-Dos',
                        'bi-check2-square',
                        route('todos.index'),
                        $context,
                    ),
                    ['open_todo_modal' => true],
                )
                : null,
        ]));

        $controlCenterHomeUrl = $this->resolveControlCenterHomeUrl($request);
        $controlAndAdminItems = array_values(array_filter([
            $controlCenterHomeUrl !== null
                ? $this->sidebarItem(
                    'control_and_admin.control_center',
                    'Control Center',
                    'bi-radar',
                    $controlCenterHomeUrl,
                    $context,
                )
                : null,
            ($isAdminTeam && $this->canAccessAdministration($user))
                ? $this->sidebarItem(
                    'control_and_admin.administration',
                    'Administration',
                    'bi-shield-lock',
                    route('admin.administration.index'),
                    $context,
                )
                : null,
            IncomingEmailAccess::allowsView($user)
                ? $this->sidebarItem(
                    'control_and_admin.learning_center',
                    'Learning Center',
                    'bi-mailbox',
                    route('admin.incoming-emails.index'),
                    $context,
                )
                : null,
        ]));

        $salesAndPurchasingHomeUrl = $this->resolveSalesAndPurchasingHomeUrl($request);

        return [
            'home' => [
                'label' => NavigationMenu::Home->label(),
                'home_url' => route(NavigationMenu::Home->homeRoute()),
                'visible' => true,
                'items' => $homeItems,
            ],
            'customers_and_service' => [
                'label' => NavigationMenu::CustomersAndService->label(),
                'home_url' => route(NavigationMenu::CustomersAndService->homeRoute()),
                'visible' => $customersAndServiceItems !== [],
                'items' => $customersAndServiceItems,
            ],
            'sales_and_purchasing' => [
                'label' => NavigationMenu::SalesAndPurchasing->label(),
                'home_url' => $salesAndPurchasingHomeUrl ?? route(NavigationMenu::SalesAndPurchasing->homeRoute()),
                'visible' => $salesAndPurchasingItems !== [],
                'items' => $salesAndPurchasingItems,
            ],
            'inventory' => [
                'label' => NavigationMenu::Inventory->label(),
                'home_url' => $inventoryItems[0]['url'] ?? route(NavigationMenu::Inventory->homeRoute()),
                'visible' => $inventoryItems !== [],
                'items' => $inventoryItems,
            ],
            'finance' => [
                'label' => NavigationMenu::Finance->label(),
                'home_url' => $financeItems[0]['url'] ?? route(NavigationMenu::Finance->homeRoute()),
                'visible' => $financeItems !== [],
                'items' => $financeItems,
            ],
            'workforce' => [
                'label' => NavigationMenu::Workforce->label(),
                'home_url' => $this->resolveWorkforceHomeUrl($request) ?? route(NavigationMenu::Workforce->homeRoute()),
                'visible' => $workforceItems !== [],
                'items' => $workforceItems,
            ],
            'control_and_admin' => [
                'label' => NavigationMenu::ControlAndAdmin->label(),
                'home_url' => $this->resolveControlAndAdminHomeUrl($request) ?? route(NavigationMenu::ControlAndAdmin->homeRoute()),
                'visible' => $controlAndAdminItems !== [],
                'items' => $controlAndAdminItems,
            ],
        ];
    }

    /**
     * @return array{0: ?NavigationMenu, 1: ?string, 2: ?string}
     */
    private function resolveRouteContext(Request $request): array
    {
        $hubTab = (string) $request->query('hub_tab', 'today');

        if ($request->routeIs('dashboard')) {
            return [NavigationMenu::Home, 'home.dashboard', null];
        }

        if ($request->routeIs('search.*', 'dashboard.*')) {
            return [NavigationMenu::CustomersAndService, 'customers_and_service.service_desk', null];
        }

        if ($request->routeIs('orders.*', 'incidents.*', 'approvals.*', 'refunds.*')) {
            return match (true) {
                $request->routeIs('orders.*') => [NavigationMenu::CustomersAndService, 'customers_and_service.orders', null],
                $request->routeIs('refunds.*') => [NavigationMenu::CustomersAndService, 'customers_and_service.refunds', null],
                $request->routeIs('approvals.*') => [NavigationMenu::CustomersAndService, 'customers_and_service.approvals', null],
                default => [NavigationMenu::CustomersAndService, 'customers_and_service.service_cases', null],
            };
        }

        if ($request->routeIs('purchasing.*')) {
            return [NavigationMenu::SalesAndPurchasing, 'sales_and_purchasing.buy_products', null];
        }

        if ($request->routeIs('service-pos.*')) {
            return [NavigationMenu::SalesAndPurchasing, 'sales_and_purchasing.sell_services', null];
        }

        if ($request->routeIs('services.*')) {
            return [NavigationMenu::SalesAndPurchasing, 'sales_and_purchasing.sell_services', null];
        }

        if ($request->routeIs('pos.sales.*')) {
            return [NavigationMenu::SalesAndPurchasing, 'sales_and_purchasing.product_sales', null];
        }

        if ($request->routeIs('pos.upi.payments.*', 'pos.upi.*', 'pos.*')) {
            return [NavigationMenu::SalesAndPurchasing, 'sales_and_purchasing.sell_products', null];
        }

        if ($request->routeIs('inventory.products.*')) {
            return [NavigationMenu::Inventory, 'inventory.products', null];
        }

        if ($request->routeIs('inventory.branches.*')) {
            return [NavigationMenu::Inventory, 'inventory.branches', null];
        }

        if ($request->routeIs('inventory.serials.*')) {
            return [NavigationMenu::Inventory, 'inventory.serials', null];
        }

        if ($request->routeIs('inventory.hardware-fulfilments.*')) {
            return [NavigationMenu::Inventory, 'inventory.hardware', null];
        }

        if ($request->routeIs('inventory.transfers.*')) {
            return [NavigationMenu::Inventory, 'inventory.transfers', null];
        }

        if ($request->routeIs('inventory.adjustments.*', 'inventory.reservations.*')) {
            return [NavigationMenu::Inventory, 'inventory.stock', null];
        }

        if ($request->routeIs('inventory.movements.*')) {
            return [NavigationMenu::Inventory, 'inventory.stock_history', null];
        }

        if ($request->routeIs('inventory.*')) {
            return [NavigationMenu::Inventory, 'inventory.stock', null];
        }

        if ($request->routeIs('cash-book.*')) {
            return [NavigationMenu::Finance, 'finance.cash_book', null];
        }

        if ($request->routeIs('finance.*')) {
            return [NavigationMenu::Finance, 'finance.dashboard', null];
        }

        if ($request->routeIs('workforce-management.recognition.*')) {
            return [NavigationMenu::Workforce, 'workforce.recognition', null];
        }

        if ($request->routeIs('workforce-management.*')) {
            return [NavigationMenu::Workforce, 'workforce.attendance', null];
        }

        if ($request->routeIs('my-workforce.*')) {
            return [NavigationMenu::Workforce, 'workforce.my_workforce', null];
        }

        if ($request->routeIs('my-performance.*')) {
            return [NavigationMenu::Workforce, 'workforce.my_performance', null];
        }

        if ($request->routeIs('leave-requests.*')) {
            return [NavigationMenu::Workforce, 'workforce.leave', null];
        }

        if ($request->routeIs('todos.*')) {
            return [NavigationMenu::Workforce, 'workforce.todos', null];
        }

        if ($request->routeIs(
            'admin.platform.*',
            'audit-logs.*',
            'cashfree.webhook-explorer.*',
            'admin.operations.automation-health*',
            'admin.automation.*',
            'admin.operations.index',
            'admin.operations.live',
            'workforce.*',
            'admin.workforce.performance.*',
        )) {
            $tabLabel = null;

            if ($request->routeIs('admin.operations.index', 'admin.operations.live')) {
                $tabLabel = match ($hubTab) {
                    'automation' => 'Automation',
                    'team' => 'Team',
                    'performance' => 'Performance',
                    'system' => 'System',
                    default => null,
                };
            }

            return [NavigationMenu::ControlAndAdmin, 'control_and_admin.control_center', $tabLabel];
        }

        if ($request->routeIs(
            'admin.administration.*',
            'users.*',
            'admin.system-settings.*',
            'settings.*',
            'admin.workforce.holidays.*',
            'admin.performance-intelligence.*',
            'admin.platform-configuration.*',
            'admin.ira-memory.*',
            'admin.incoming-emails.*',
            'admin.backups.*',
            'admin.gmail.*',
        )) {
            if ($request->routeIs('admin.incoming-emails.*')) {
                return [NavigationMenu::ControlAndAdmin, 'control_and_admin.learning_center', null];
            }

            return [NavigationMenu::ControlAndAdmin, 'control_and_admin.administration', null];
        }

        return [NavigationMenu::Home, null, null];
    }

    private function resolveMenuHomeUrl(Request $request, ?NavigationMenu $menu): ?string
    {
        if ($menu === null) {
            return null;
        }

        return match ($menu) {
            NavigationMenu::ControlAndAdmin => $this->resolveControlAndAdminHomeUrl($request),
            NavigationMenu::SalesAndPurchasing => $this->resolveSalesAndPurchasingHomeUrl($request),
            NavigationMenu::Workforce => $this->resolveWorkforceHomeUrl($request),
            default => route($menu->homeRoute()),
        };
    }

    private function resolveControlAndAdminHomeUrl(Request $request): ?string
    {
        $controlCenterHomeUrl = $this->resolveControlCenterHomeUrl($request);

        if ($controlCenterHomeUrl !== null) {
            return $controlCenterHomeUrl;
        }

        $user = $request->user();

        if ($user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) && $this->canAccessAdministration($user)) {
            return route('admin.administration.index');
        }

        if (IncomingEmailAccess::allowsView($user)) {
            return route('admin.incoming-emails.index');
        }

        return null;
    }

    private function resolveControlCenterHomeUrl(Request $request): ?string
    {
        $user = $request->user();

        if ($user?->can('platform-dashboard.view')) {
            return route('admin.platform.index');
        }

        if ($user?->can('operations-dashboard.view')) {
            return route('admin.operations.index');
        }

        if ($user?->can('workforce360.viewTeam')) {
            return route('workforce.index');
        }

        if ($user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) && $user->can('team-performance.view')) {
            return route('admin.workforce.performance.index');
        }

        if ($user?->can('automation-operations.view')) {
            return route('admin.operations.index', ['hub_tab' => 'automation']);
        }

        if (Gate::check('viewAny', AuditLog::class)) {
            return route('audit-logs.index');
        }

        if (Gate::check('viewAny', CashfreeWebhookLog::class)) {
            return route('cashfree.webhook-explorer.index');
        }

        return null;
    }

    private function resolveSalesAndPurchasingHomeUrl(Request $request): ?string
    {
        $user = $request->user();

        if (PosAccess::allows($user)) {
            return route('pos.counter.create');
        }

        if (ServiceAccess::allowsSell($user)) {
            return route('service-pos.counter.create');
        }

        if (PurchasingAccess::allows($user)) {
            return route('purchasing.purchase-orders.index');
        }

        return null;
    }

    private function resolveWorkforceHomeUrl(Request $request): ?string
    {
        $user = $request->user();
        $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

        if ($isAdminTeam && AttendanceManagementAccess::allows($user)) {
            return route('workforce-management.attendance.index');
        }

        if ($user?->can('workforce360.viewSelf')) {
            return route('my-workforce.index');
        }

        if ($this->canViewMyPerformance($user)) {
            return route('my-performance.index');
        }

        if (Gate::check('viewAny', LeaveRequest::class)) {
            return route('leave-requests.index');
        }

        if (Gate::check('viewAny', Todo::class)) {
            return route('todos.index');
        }

        return null;
    }

    /**
     * @return list<array{label: string, url: ?string}>
     */
    private function buildBreadcrumbs(
        ?NavigationMenu $menu,
        string $pageTitle,
        ?string $suffix,
        ?string $menuHomeUrl,
    ): array {
        if ($menu === null) {
            return [['label' => $pageTitle, 'url' => null]];
        }

        $breadcrumbs = [
            [
                'label' => $menu->label(),
                'url' => $menuHomeUrl,
            ],
        ];

        if ($suffix !== null) {
            $breadcrumbs[] = [
                'label' => $suffix,
                'url' => null,
            ];

            return $breadcrumbs;
        }

        if ($this->isMenuHomeTitle($menu, $pageTitle)) {
            $breadcrumbs[0]['url'] = null;

            return $breadcrumbs;
        }

        $breadcrumbs[] = [
            'label' => $pageTitle,
            'url' => null,
        ];

        return $breadcrumbs;
    }

    private function isMenuHomeTitle(NavigationMenu $menu, string $pageTitle): bool
    {
        return match ($menu) {
            NavigationMenu::Home => $pageTitle === 'Dashboard',
            NavigationMenu::CustomersAndService => in_array($pageTitle, ['Dashboard', 'Service Desk'], true),
            NavigationMenu::SalesAndPurchasing => in_array($pageTitle, ['POS', 'POS counter'], true),
            NavigationMenu::Inventory => in_array($pageTitle, ['Inventory', 'Stock'], true),
            NavigationMenu::Finance => in_array($pageTitle, ['Finance', 'Dashboard'], true),
            NavigationMenu::Workforce => in_array($pageTitle, ['Workforce Management', 'Attendance', 'My Workforce', 'My Performance', 'Leave Requests', 'To-Dos'], true),
            NavigationMenu::ControlAndAdmin => in_array($pageTitle, ['Administration', 'Command Center', 'Mission Control'], true),
        };
    }

    private function shouldShowBreadcrumb(Request $request): bool
    {
        if ($request->routeIs('*.show', '*.create', '*.edit')) {
            return false;
        }

        return true;
    }

    private function canAccessAdministration(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return Gate::check('viewAny', User::class)
            || Gate::check('viewAny', SystemSetting::class)
            || $user->can('system-settings.manage')
            || Gate::check('viewAny', CompanyHoliday::class);
    }

    private function canViewMyPerformance(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! app(OperationsRoleService::class)->isTeamMember($user)) {
            return false;
        }

        return $user->hasAnyRole(RolePermissionSeeder::SUPPORT_TEAM_ROLES)
            || $user->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)
            || $user->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    /**
     * @return array<string, mixed>
     */
    private function sidebarItem(
        string $key,
        string $label,
        string $icon,
        string $url,
        NavigationContext $context,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'url' => $url,
            'title' => $label,
            'active' => $context->isActive($key),
        ];
    }
}
