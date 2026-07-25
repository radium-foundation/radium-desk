<?php

namespace App\Support\Navigation;

use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\LeaveRequest;
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
     *     operations: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     control_center: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     administration: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     *     super_admin: array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>},
     * }
     */
    public function sidebar(Request $request, NavigationContext $context): array
    {
        $user = $request->user();
        $isAdminTeam = $user?->hasAnyRole(RolePermissionSeeder::ADMIN_TEAM_ROLES) ?? false;
        $canViewPlatform = $user?->can('platform-dashboard.view') ?? false;

        $operationsItems = array_values(array_filter([
            $this->sidebarItem('operations.dashboard', 'Dashboard', 'bi-speedometer2', route('dashboard'), $context),
            $this->sidebarItem('operations.search', 'Search', 'bi-search', route('search.index'), $context),
            $this->sidebarItem('operations.orders', 'Orders', 'bi-box-seam', route('orders.index'), $context),
            $this->sidebarItem(
                'operations.incidents',
                config('ui.service_case.plural'),
                'bi-exclamation-triangle',
                route('incidents.index'),
                $context,
            ),
            $this->sidebarItem('operations.approvals', 'Approvals', 'bi-check2-square', route('approvals.index'), $context),
            $this->sidebarItem('operations.refunds', 'Refunds', 'bi-currency-exchange', route('refunds.index'), $context),
            $user?->can('workforce360.viewSelf')
                ? $this->sidebarItem('operations.my_workforce', 'My Workforce', 'bi-person-workspace', route('my-workforce.index'), $context)
                : null,
            ($user?->hasAnyRole(RolePermissionSeeder::SUPPORT_TEAM_ROLES)
                || $user?->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)
                || $user?->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM))
                ? $this->sidebarItem('operations.my_performance', 'My Performance', 'bi-bar-chart', route('my-performance.index'), $context)
                : null,
            (! $isAdminTeam && Gate::check('viewAny', LeaveRequest::class))
                ? $this->sidebarItem('operations.my_leave', 'My Leave', 'bi-calendar-x', route('leave-requests.index'), $context)
                : null,
        ]));

        $controlCenterHomeUrl = $this->resolveMenuHomeUrl($request, NavigationMenu::ControlCenter);
        $controlCenterItems = $controlCenterHomeUrl !== null
            ? [
                $this->sidebarItem(
                    'control_center.home',
                    'Control Center',
                    'bi-sliders',
                    $controlCenterHomeUrl,
                    $context,
                ),
            ]
            : [];

        $administrationItems = $isAdminTeam
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

        $superAdminItems = array_values(array_filter([
            $canViewPlatform
                ? $this->sidebarItem(
                    'super_admin.mission_control',
                    'Mission Control',
                    'bi-radar',
                    route('admin.platform.index'),
                    $context,
                )
                : null,
            (! $canViewPlatform && Gate::check('viewAny', AuditLog::class))
                ? $this->sidebarItem('super_admin.audit_logs', 'Audit Logs', 'bi-journal-text', route('audit-logs.index'), $context)
                : null,
            (! $canViewPlatform && Gate::check('viewAny', CashfreeWebhookLog::class))
                ? $this->sidebarItem(
                    'super_admin.webhook_explorer',
                    'Webhook Explorer',
                    'bi-broadcast',
                    route('cashfree.webhook-explorer.index'),
                    $context,
                )
                : null,
            (! $canViewPlatform && $user?->can('automation-operations.view'))
                ? $this->sidebarItem(
                    'super_admin.automation',
                    'Automation',
                    'bi-robot',
                    route('admin.operations.index', ['hub_tab' => 'automation']),
                    $context,
                )
                : null,
        ]));

        return [
            'operations' => [
                'label' => NavigationMenu::Operations->label(),
                'home_url' => route(NavigationMenu::Operations->homeRoute()),
                'visible' => $operationsItems !== [],
                'items' => $operationsItems,
            ],
            'control_center' => [
                'label' => NavigationMenu::ControlCenter->label(),
                'home_url' => $controlCenterHomeUrl ?? route(NavigationMenu::ControlCenter->homeRoute()),
                'visible' => $controlCenterItems !== [],
                'items' => $controlCenterItems,
            ],
            'administration' => [
                'label' => NavigationMenu::Administration->label(),
                'home_url' => route(NavigationMenu::Administration->homeRoute()),
                'visible' => $isAdminTeam && $administrationItems !== [],
                'items' => $administrationItems,
            ],
            'super_admin' => [
                'label' => NavigationMenu::SuperAdmin->label(),
                'home_url' => $canViewPlatform
                    ? route(NavigationMenu::SuperAdmin->homeRoute())
                    : ($superAdminItems[0]['url'] ?? route('audit-logs.index')),
                'visible' => $isAdminTeam && $superAdminItems !== [],
                'items' => $superAdminItems,
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
        $canViewPlatform = $request->user()?->can('platform-dashboard.view') ?? false;

        if ($request->routeIs('admin.platform.*')) {
            return [NavigationMenu::SuperAdmin, 'super_admin.mission_control', null];
        }

        if ($request->routeIs('audit-logs.*')) {
            return [
                NavigationMenu::SuperAdmin,
                $canViewPlatform ? 'super_admin.mission_control' : 'super_admin.audit_logs',
                null,
            ];
        }

        if ($request->routeIs('cashfree.webhook-explorer.*')) {
            return [
                NavigationMenu::SuperAdmin,
                $canViewPlatform ? 'super_admin.mission_control' : 'super_admin.webhook_explorer',
                null,
            ];
        }

        if ($request->routeIs('admin.operations.automation-health*', 'admin.automation.*')) {
            return [
                NavigationMenu::SuperAdmin,
                $canViewPlatform ? 'super_admin.mission_control' : 'super_admin.automation',
                null,
            ];
        }

        if ($request->routeIs('admin.operations.index', 'admin.operations.live')) {
            if ($hubTab === 'automation') {
                return [
                    NavigationMenu::SuperAdmin,
                    $canViewPlatform ? 'super_admin.mission_control' : 'super_admin.automation',
                    'Automation',
                ];
            }

            $tabLabel = match ($hubTab) {
                'team' => 'Team',
                'performance' => 'Performance',
                'system' => 'System',
                'today' => null,
                default => null,
            };

            return [NavigationMenu::ControlCenter, 'control_center.home', $tabLabel];
        }

        if ($request->routeIs('workforce.*') && ! $request->routeIs('admin.workforce.*')) {
            return [NavigationMenu::ControlCenter, 'control_center.home', null];
        }

        if ($request->routeIs('admin.workforce.performance.*')) {
            return [NavigationMenu::ControlCenter, 'control_center.home', null];
        }

        if ($request->routeIs('leave-requests.*')) {
            if ($isAdminTeam) {
                return [NavigationMenu::ControlCenter, 'control_center.home', null];
            }

            return [NavigationMenu::Operations, 'operations.my_leave', null];
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
            return [NavigationMenu::Operations, 'operations.dashboard', null];
        }

        if ($request->routeIs('search.*')) {
            return [NavigationMenu::Operations, 'operations.search', null];
        }

        if ($request->routeIs('orders.*')) {
            return [NavigationMenu::Operations, 'operations.orders', null];
        }

        if ($request->routeIs('incidents.*')) {
            return [NavigationMenu::Operations, 'operations.incidents', null];
        }

        if ($request->routeIs('approvals.*')) {
            return [NavigationMenu::Operations, 'operations.approvals', null];
        }

        if ($request->routeIs('refunds.*')) {
            return [NavigationMenu::Operations, 'operations.refunds', null];
        }

        if ($request->routeIs('my-workforce.*')) {
            return [NavigationMenu::Operations, 'operations.my_workforce', null];
        }

        if ($request->routeIs('my-performance.*')) {
            return [NavigationMenu::Operations, 'operations.my_performance', null];
        }

        return [NavigationMenu::Operations, null, null];
    }

    private function resolveMenuHomeUrl(Request $request, ?NavigationMenu $menu): ?string
    {
        if ($menu === null) {
            return null;
        }

        if ($menu === NavigationMenu::ControlCenter) {
            $user = $request->user();

            if ($user?->can('operations-dashboard.view')) {
                return route('admin.operations.index');
            }

            if ($user?->can('workforce360.viewTeam')) {
                return route('workforce.index');
            }

            return null;
        }

        if ($menu === NavigationMenu::SuperAdmin) {
            $user = $request->user();

            if ($user?->can('platform-dashboard.view')) {
                return route('admin.platform.index');
            }

            if (Gate::check('viewAny', AuditLog::class)) {
                return route('audit-logs.index');
            }

            if (Gate::check('viewAny', CashfreeWebhookLog::class)) {
                return route('cashfree.webhook-explorer.index');
            }

            if ($user?->can('automation-operations.view')) {
                return route('admin.operations.index', ['hub_tab' => 'automation']);
            }

            return route('admin.platform.index');
        }

        return route($menu->homeRoute());
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
            if ($suffix === 'Automation') {
                $breadcrumbs[] = [
                    'label' => 'Automation',
                    'url' => null,
                ];

                return $breadcrumbs;
            }

            $breadcrumbs[] = [
                'label' => 'Operations Control Center',
                'url' => route('admin.operations.index'),
            ];
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
            NavigationMenu::Operations => in_array($pageTitle, ['Dashboard'], true),
            NavigationMenu::ControlCenter => in_array($pageTitle, ['Operations Control Center', 'Control Center'], true),
            NavigationMenu::Administration => $pageTitle === 'Administration',
            NavigationMenu::SuperAdmin => in_array($pageTitle, ['Command Center', 'Mission Control'], true),
        };
    }

    private function shouldShowBreadcrumb(Request $request): bool
    {
        if ($request->routeIs('*.show', '*.create', '*.edit')) {
            return false;
        }

        return true;
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
