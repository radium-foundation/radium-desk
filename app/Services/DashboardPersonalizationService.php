<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardPersonalizationService
{
    public const VIEW_ALL = 'all';

    public const VIEW_TEAM = 'team';

    public const VIEW_MY_WORK = 'my_work';

    public const VIEW_HARDWARE_ORDERS = 'hardware_orders';

    public const PERMISSION_HARDWARE_VIEW = 'dashboard.hardware.view';

    /**
     * @var list<string>
     */
    private const HARDWARE_VIEW_ALIASES = [
        'hardware',
        'hardware_orders',
        'warehouse',
        'dispatch',
    ];

    public function defaultViewFor(User $user): string
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return self::VIEW_ALL;
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            return self::VIEW_TEAM;
        }

        return self::VIEW_MY_WORK;
    }

    public function canViewHardwareOrders(User $user): bool
    {
        return $user->can(self::PERMISSION_HARDWARE_VIEW);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public function availableModulesFor(User $user): array
    {
        $modules = config('ui.dashboard.modules', []);

        if ($user->hasRole(RolePermissionSeeder::ROLE_AGENT)) {
            $allowed = [self::VIEW_MY_WORK, self::VIEW_TEAM];

            return $this->filterModules($modules, $allowed, $user);
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            $allowed = [self::VIEW_MY_WORK, self::VIEW_TEAM, self::VIEW_HARDWARE_ORDERS];

            return $this->filterModules($modules, $allowed, $user);
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            $allowed = [self::VIEW_ALL, self::VIEW_TEAM, self::VIEW_HARDWARE_ORDERS];

            return $this->filterModules($modules, $allowed, $user);
        }

        return [];
    }

    public function showsModuleNavigation(User $user): bool
    {
        return $this->availableModulesFor($user) !== [];
    }

    public function normalizeRequestedView(?string $requestedView): ?string
    {
        if ($requestedView === null || $requestedView === '') {
            return null;
        }

        if (in_array($requestedView, self::HARDWARE_VIEW_ALIASES, true)) {
            return self::VIEW_HARDWARE_ORDERS;
        }

        return $requestedView;
    }

    /**
     * @return array{view: string, redirect: bool}
     */
    public function resolveView(User $user, ?string $requestedView): array
    {
        $normalized = $this->normalizeRequestedView($requestedView);
        $defaultView = $this->defaultViewFor($user);
        $availableViews = array_keys($this->availableModulesFor($user));

        if ($normalized === self::VIEW_HARDWARE_ORDERS && ! $this->canViewHardwareOrders($user)) {
            return ['view' => $defaultView, 'redirect' => true];
        }

        if ($normalized === null) {
            return ['view' => $defaultView, 'redirect' => false];
        }

        if ($normalized === self::VIEW_HARDWARE_ORDERS) {
            return ['view' => self::VIEW_HARDWARE_ORDERS, 'redirect' => false];
        }

        if (! in_array($normalized, $availableViews, true)) {
            return ['view' => $defaultView, 'redirect' => true];
        }

        return ['view' => $normalized, 'redirect' => false];
    }

    public function redirectToResolvedView(Request $request, User $user, string $view, string $filter): ?RedirectResponse
    {
        $params = [];

        if ($view !== $this->defaultViewFor($user)) {
            $params['view'] = $view;
        }

        if ($filter !== $this->defaultFilterFor($user, $view)) {
            $params['filter'] = $filter;
        }

        if ($params === [] && $request->query() === []) {
            return null;
        }

        if ($params === [] && $request->query() !== []) {
            return redirect()->route('dashboard');
        }

        $currentParams = array_filter([
            'view' => $request->query('view'),
            'filter' => $request->query('filter'),
        ], fn (mixed $value): bool => filled($value));

        if ($currentParams === $params) {
            return null;
        }

        return redirect()->route('dashboard', $params);
    }

    public function defaultFilterFor(User $user, string $view): string
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_AGENT)) {
            return 'pending_admin';
        }

        if ($view === self::VIEW_ALL) {
            return 'all';
        }

        return 'pending_admin';
    }

    /**
     * @return list<string>
     */
    public function availableFiltersFor(User $user): array
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_AGENT)) {
            return ['pending_admin', 'high_priority', 'all'];
        }

        return ['all', 'pending_admin', 'completed', 'high_priority', 'overdue', 'warning'];
    }

    public function serviceCasePanelTitle(string $view): string
    {
        return match ($view) {
            self::VIEW_MY_WORK => 'My Work',
            self::VIEW_TEAM => 'Team Service Cases',
            self::VIEW_ALL => 'All Service Cases',
            default => 'Recent Service Cases',
        };
    }

    public function scopesServiceCasesToAssignee(string $view): bool
    {
        return $view === self::VIEW_MY_WORK;
    }

    public function prioritizesRecentAssignments(string $view): bool
    {
        return $view === self::VIEW_MY_WORK;
    }

    public function showsServiceCasesPanel(string $view): bool
    {
        return in_array($view, [self::VIEW_ALL, self::VIEW_TEAM, self::VIEW_MY_WORK], true);
    }

    public function showsHardwareOrdersPanel(string $view): bool
    {
        return $view === self::VIEW_HARDWARE_ORDERS;
    }

    /**
     * @param  array<string, array{label: string, icon: string}>  $modules
     * @param  list<string>  $allowed
     * @return array<string, array{label: string, icon: string}>
     */
    private function filterModules(array $modules, array $allowed, User $user): array
    {
        $filtered = [];

        foreach ($allowed as $moduleKey) {
            if ($moduleKey === self::VIEW_HARDWARE_ORDERS && ! $this->canViewHardwareOrders($user)) {
                continue;
            }

            if (isset($modules[$moduleKey])) {
                $filtered[$moduleKey] = $modules[$moduleKey];
            }
        }

        return $filtered;
    }
}
