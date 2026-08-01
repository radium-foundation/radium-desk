<?php

namespace App\Support\Navigation;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use App\Support\Finance\FinanceAccess;
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
     *     dashboard: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     operations: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     mission_control: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     workforce_management: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     finance: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     administration: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     personal: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     * }
     */
    public function sidebar(Request $request, NavigationContext $context): array
    {
        $user = $request->user();
        $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

        $dashboardItems = [
            $this->sidebarItem('dashboard.home', 'Dashboard', 'bi-speedometer2', route('dashboard'), $context),
        ];

        $operationsItems = array_values(array_filter([
            Gate::check('viewAny', Order::class)
                ? $this->sidebarItem('operations.orders', 'Orders', 'bi-box-seam', route('orders.index'), $context)
                : null,
            Gate::check('viewAny', Incident::class)
                ? $this->sidebarItem(
                    'operations.incidents',
                    config('ui.service_case.plural'),
                    'bi-exclamation-triangle',
                    route('incidents.index'),
                    $context,
                )
                : null,
            Gate::check('viewAny', RefundRequest::class)
                ? $this->sidebarItem('operations.refunds', 'Refunds', 'bi-currency-exchange', route('refunds.index'), $context)
                : null,
        ]));

        $missionControlHomeUrl = $this->resolveMenuHomeUrl($request, NavigationMenu::MissionControl);
        $missionControlItems = $missionControlHomeUrl !== null
            ? [
                $this->sidebarItem(
                    'mission_control.home',
                    'Mission Control',
                    'bi-radar',
                    $missionControlHomeUrl,
                    $context,
                ),
            ]
            : [];

        $workforceManagementItems = ($isAdminTeam && AttendanceManagementAccess::allows($user))
            ? array_values(array_filter([
                $this->sidebarItem(
                    'workforce_management.attendance',
                    'Attendance',
                    'bi-calendar2-check',
                    route('workforce-management.attendance.index'),
                    $context,
                ),
                (config('workforce_recognition.enabled') && $user?->can('workforce.recognition.view'))
                    ? $this->sidebarItem(
                        'workforce_management.recognition',
                        'Work Recognition',
                        'bi-award',
                        route('workforce-management.recognition.index'),
                        $context,
                    )
                    : null,
            ]))
            : [];

        $financeItems = FinanceAccess::allows($user)
            ? [
                $this->sidebarItem(
                    'finance.dashboard',
                    'Finance',
                    'bi-wallet2',
                    route('finance.dashboard'),
                    $context,
                ),
            ]
            : [];

        $administrationItems = ($isAdminTeam && $this->canAccessAdministration($user))
            ? [
                $this->sidebarItem(
                    'administration.home',
                    'Administration',
                    'bi-shield-lock',
                    route('admin.administration.index'),
                    $context,
                ),
            ]
            : [];

        $personalItems = array_values(array_filter([
            $user?->can('workforce360.viewSelf')
                ? $this->sidebarItem('personal.my_workforce', 'My Workforce', 'bi-person-workspace', route('my-workforce.index'), $context)
                : null,
            $this->canViewMyPerformance($user)
                ? $this->sidebarItem('personal.my_performance', 'My Performance', 'bi-bar-chart', route('my-performance.index'), $context)
                : null,
            (! $isAdminTeam && Gate::check('viewAny', LeaveRequest::class))
                ? $this->sidebarItem('personal.my_leave', 'My Leave', 'bi-calendar-x', route('leave-requests.index'), $context)
                : null,
        ]));

        return [
            'dashboard' => [
                'label' => NavigationMenu::Dashboard->label(),
                'home_url' => route(NavigationMenu::Dashboard->homeRoute()),
                'visible' => true,
                'items' => $dashboardItems,
            ],
            'operations' => [
                'label' => NavigationMenu::Operations->label(),
                'home_url' => $operationsItems[0]['url'] ?? route('dashboard'),
                'visible' => $operationsItems !== [],
                'items' => $operationsItems,
            ],
            'mission_control' => [
                'label' => NavigationMenu::MissionControl->label(),
                'home_url' => $missionControlHomeUrl ?? route(NavigationMenu::MissionControl->homeRoute()),
                'visible' => $missionControlItems !== [],
                'items' => $missionControlItems,
            ],
            'workforce_management' => [
                'label' => NavigationMenu::WorkforceManagement->label(),
                'home_url' => route(NavigationMenu::WorkforceManagement->homeRoute()),
                'visible' => $workforceManagementItems !== [],
                'items' => $workforceManagementItems,
            ],
            'finance' => [
                'label' => NavigationMenu::Finance->label(),
                'home_url' => route(NavigationMenu::Finance->homeRoute()),
                'visible' => $financeItems !== [],
                'items' => $financeItems,
            ],
            'administration' => [
                'label' => NavigationMenu::Administration->label(),
                'home_url' => route(NavigationMenu::Administration->homeRoute()),
                'visible' => $administrationItems !== [],
                'items' => $administrationItems,
            ],
            'personal' => [
                'label' => NavigationMenu::Personal->label(),
                'home_url' => $personalItems[0]['url'] ?? route('my-workforce.index'),
                'visible' => $personalItems !== [],
                'items' => $personalItems,
            ],
        ];
    }

    /**
     * @return array{0: ?NavigationMenu, 1: ?string, 2: ?string}
     */
    private function resolveRouteContext(Request $request): array
    {
        $hubTab = (string) $request->query('hub_tab', 'today');
        $isAdminTeam = $request->user()?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;

        if ($request->routeIs('admin.platform.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('audit-logs.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('cashfree.webhook-explorer.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('admin.operations.automation-health*', 'admin.automation.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('admin.operations.index', 'admin.operations.live')) {
            $tabLabel = match ($hubTab) {
                'automation' => 'Automation',
                'team' => 'Team',
                'performance' => 'Performance',
                'system' => 'System',
                default => null,
            };

            return [NavigationMenu::MissionControl, 'mission_control.home', $tabLabel];
        }

        if ($request->routeIs('workforce.*') && ! $request->routeIs('admin.workforce.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('admin.workforce.performance.*')) {
            return [NavigationMenu::MissionControl, 'mission_control.home', null];
        }

        if ($request->routeIs('workforce-management.recognition.*')) {
            return [NavigationMenu::WorkforceManagement, 'workforce_management.recognition', null];
        }

        if ($request->routeIs('workforce-management.*')) {
            return [NavigationMenu::WorkforceManagement, 'workforce_management.attendance', null];
        }

        if ($request->routeIs('finance.*')) {
            return [NavigationMenu::Finance, 'finance.dashboard', null];
        }

        if ($request->routeIs('leave-requests.*')) {
            if ($isAdminTeam) {
                return [NavigationMenu::MissionControl, 'mission_control.home', null];
            }

            return [NavigationMenu::Personal, 'personal.my_leave', null];
        }

        if ($request->routeIs('admin.administration.*')) {
            return [NavigationMenu::Administration, 'administration.home', null];
        }

        if ($request->routeIs('users.*')) {
            return [NavigationMenu::Administration, 'administration.home', null];
        }

        if ($request->routeIs('admin.system-settings.*')) {
            return [NavigationMenu::Administration, 'administration.home', null];
        }

        if ($request->routeIs('settings.*')) {
            return [NavigationMenu::Administration, 'administration.home', null];
        }

        if ($request->routeIs('admin.workforce.holidays.*')) {
            return [NavigationMenu::Administration, 'administration.home', null];
        }

        if ($request->routeIs('dashboard')) {
            return [NavigationMenu::Dashboard, 'dashboard.home', null];
        }

        if ($request->routeIs('search.*')) {
            return [NavigationMenu::Dashboard, null, null];
        }

        if ($request->routeIs('orders.*')) {
            return [NavigationMenu::Operations, 'operations.orders', null];
        }

        if ($request->routeIs('incidents.*')) {
            return [NavigationMenu::Operations, 'operations.incidents', null];
        }

        if ($request->routeIs('approvals.*')) {
            return [NavigationMenu::Operations, 'operations.incidents', null];
        }

        if ($request->routeIs('refunds.*')) {
            return [NavigationMenu::Operations, 'operations.refunds', null];
        }

        if ($request->routeIs('my-workforce.*')) {
            return [NavigationMenu::Personal, 'personal.my_workforce', null];
        }

        if ($request->routeIs('my-performance.*')) {
            return [NavigationMenu::Personal, 'personal.my_performance', null];
        }

        return [NavigationMenu::Dashboard, null, null];
    }

    private function resolveMenuHomeUrl(Request $request, ?NavigationMenu $menu): ?string
    {
        if ($menu === null) {
            return null;
        }

        if ($menu === NavigationMenu::MissionControl) {
            return $this->resolveMissionControlHomeUrl($request);
        }

        if ($menu === NavigationMenu::Personal) {
            $user = $request->user();

            if ($user?->can('workforce360.viewSelf')) {
                return route('my-workforce.index');
            }

            if ($this->canViewMyPerformance($user)) {
                return route('my-performance.index');
            }

            if (Gate::check('viewAny', LeaveRequest::class)) {
                return route('leave-requests.index');
            }

            return null;
        }

        return route($menu->homeRoute());
    }

    private function resolveMissionControlHomeUrl(Request $request): ?string
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

        if ($user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) && Gate::check('viewAny', LeaveRequest::class)) {
            return route('leave-requests.index');
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
            NavigationMenu::Dashboard => $pageTitle === 'Dashboard',
            NavigationMenu::Operations => false,
            NavigationMenu::MissionControl => in_array($pageTitle, ['Command Center', 'Mission Control'], true),
            NavigationMenu::WorkforceManagement => in_array($pageTitle, ['Workforce Management', 'Attendance'], true),
            NavigationMenu::Finance => in_array($pageTitle, ['Finance', 'Dashboard'], true),
            NavigationMenu::Administration => $pageTitle === 'Administration',
            NavigationMenu::Personal => in_array($pageTitle, ['My Workforce', 'My Performance', 'Leave Requests'], true),
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
            || Gate::check('viewAny', \App\Models\CompanyHoliday::class);
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
