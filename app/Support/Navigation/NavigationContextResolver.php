<?php

namespace App\Support\Navigation;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SystemSetting;
use App\Models\User;
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
     * @return array<string, array{label: string, home_url: string, visible: bool, destination: array<string, mixed>}>
     */
    public function sidebar(Request $request, NavigationContext $context): array
    {
        $user = $request->user();

        return [
            'home_desk' => $this->destinationGroup(
                NavigationMenu::HomeDesk,
                route('dashboard'),
                $context,
                visible: true,
            ),
            'commerce' => $this->destinationGroup(
                NavigationMenu::Commerce,
                $this->resolveCommerceHomeUrl($request) ?? route(NavigationMenu::Commerce->homeRoute()),
                $context,
                visible: $this->canAccessCommerce($user),
            ),
            'inventory' => $this->destinationGroup(
                NavigationMenu::Inventory,
                route(NavigationMenu::Inventory->homeRoute()),
                $context,
                visible: InventoryAccess::allows($user),
            ),
            'finance' => $this->destinationGroup(
                NavigationMenu::Finance,
                $this->resolveFinanceHomeUrl($request) ?? route(NavigationMenu::Finance->homeRoute()),
                $context,
                visible: $this->canAccessFinance($user),
            ),
            'control_and_admin' => $this->destinationGroup(
                NavigationMenu::ControlAndAdmin,
                $this->resolveControlAndAdminHomeUrl($request) ?? route(NavigationMenu::ControlAndAdmin->homeRoute()),
                $context,
                visible: $this->canAccessControlAndAdmin($request),
            ),
        ];
    }

    /**
     * @return array{label: string, home_url: string, visible: bool, destination: array<string, mixed>}
     */
    private function destinationGroup(
        NavigationMenu $menu,
        string $url,
        NavigationContext $context,
        bool $visible,
    ): array {
        return [
            'label' => $menu->label(),
            'home_url' => $url,
            'visible' => $visible,
            'destination' => [
                'key' => $menu->value.'.destination',
                'label' => $menu->label(),
                'icon' => $menu->icon(),
                'url' => $url,
                'title' => $menu->label(),
                'active' => $context->menu === $menu,
            ],
        ];
    }

    /**
     * @return array{0: ?NavigationMenu, 1: ?string, 2: ?string}
     */
    private function resolveRouteContext(Request $request): array
    {
        $hubTab = (string) $request->query('hub_tab', 'today');

        if ($request->routeIs(
            'dashboard',
            'search.*',
            'dashboard.*',
            'incidents.*',
            'my-workforce.*',
            'my-performance.*',
            'todos.*',
        )) {
            return [NavigationMenu::HomeDesk, 'home_desk.destination', null];
        }

        if ($request->routeIs('approvals.*')) {
            return [NavigationMenu::HomeDesk, 'home_desk.approvals', null];
        }

        if ($request->routeIs(
            'orders.*',
            'purchasing.*',
            'service-pos.*',
            'services.*',
            'pos.sales.*',
            'pos.upi.payments.*',
            'pos.upi.*',
            'pos.*',
            'inventory.hardware-fulfilments.*',
        )) {
            return [NavigationMenu::Commerce, 'commerce.destination', null];
        }

        if ($request->routeIs('inventory.*')) {
            return [NavigationMenu::Inventory, 'inventory.destination', null];
        }

        if ($request->routeIs('cash-book.*', 'refunds.*', 'finance.*')) {
            return [NavigationMenu::Finance, 'finance.destination', null];
        }

        if ($request->routeIs(
            'workforce-management.*',
            'leave-requests.*',
            'admin.platform.*',
            'audit-logs.*',
            'cashfree.webhook-explorer.*',
            'admin.operations.automation-health*',
            'admin.automation.*',
            'admin.operations.index',
            'admin.operations.live',
            'workforce.*',
            'admin.workforce.performance.*',
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

            return [NavigationMenu::ControlAndAdmin, 'control_and_admin.destination', $tabLabel];
        }

        return [NavigationMenu::HomeDesk, null, null];
    }

    private function resolveMenuHomeUrl(Request $request, ?NavigationMenu $menu): ?string
    {
        if ($menu === null) {
            return null;
        }

        return match ($menu) {
            NavigationMenu::Commerce => $this->resolveCommerceHomeUrl($request),
            NavigationMenu::Finance => $this->resolveFinanceHomeUrl($request),
            NavigationMenu::ControlAndAdmin => $this->resolveControlAndAdminHomeUrl($request),
            default => route($menu->homeRoute()),
        };
    }

    private function resolveCommerceHomeUrl(Request $request): ?string
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

        if (Gate::check('viewAny', Order::class)) {
            return route('orders.index');
        }

        if (HardwareFulfilmentAccess::allows($user)) {
            return route('inventory.hardware-fulfilments.index');
        }

        return null;
    }

    private function resolveFinanceHomeUrl(Request $request): ?string
    {
        $user = $request->user();

        if (FinanceAccess::allows($user)) {
            return route('finance.dashboard');
        }

        if ($user?->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)) {
            return route('cash-book.index');
        }

        if (Gate::check('viewAny', RefundRequest::class)) {
            return route('refunds.index');
        }

        return null;
    }

    private function resolveControlAndAdminHomeUrl(Request $request): ?string
    {
        $user = $request->user();
        $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

        if ($isAdminTeam && AttendanceManagementAccess::allows($user)) {
            return route('workforce-management.attendance.index');
        }

        $controlCenterHomeUrl = $this->resolveControlCenterHomeUrl($request);

        if ($controlCenterHomeUrl !== null) {
            return $controlCenterHomeUrl;
        }

        if (Gate::check('viewAny', LeaveRequest::class)) {
            return route('leave-requests.index');
        }

        if ($isAdminTeam && $this->canAccessAdministration($user)) {
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

    private function canAccessCommerce(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return PosAccess::allows($user)
            || ServiceAccess::allowsSell($user)
            || PurchasingAccess::allows($user)
            || Gate::check('viewAny', Order::class)
            || HardwareFulfilmentAccess::allows($user);
    }

    private function canAccessFinance(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return FinanceAccess::allows($user)
            || $user->can(RolePermissionSeeder::PERMISSION_CASHBOOK_VIEW)
            || Gate::check('viewAny', RefundRequest::class);
    }

    private function canAccessControlAndAdmin(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $isAdminTeam = $user->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES);

        return ($isAdminTeam && AttendanceManagementAccess::allows($user))
            || Gate::check('viewAny', LeaveRequest::class)
            || $this->resolveControlCenterHomeUrl($request) !== null
            || ($isAdminTeam && $this->canAccessAdministration($user))
            || IncomingEmailAccess::allowsView($user)
            || ($isAdminTeam && config('workforce_recognition.enabled') && $user->can('workforce.recognition.view'));
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
            NavigationMenu::HomeDesk => in_array($pageTitle, ['Dashboard', 'My Workforce', 'My Performance', 'To-Dos', 'Leave Requests'], true),
            NavigationMenu::Commerce => in_array($pageTitle, ['POS', 'POS counter', 'Orders', 'Refunds'], true),
            NavigationMenu::Inventory => in_array($pageTitle, ['Inventory', 'Stock'], true),
            NavigationMenu::Finance => in_array($pageTitle, ['Finance', 'Dashboard', 'Cash Book'], true),
            NavigationMenu::ControlAndAdmin => in_array($pageTitle, ['Administration', 'Command Center', 'Mission Control', 'Attendance'], true),
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
}
