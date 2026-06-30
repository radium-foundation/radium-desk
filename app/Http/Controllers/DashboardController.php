<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $viewResolution = $this->dashboardPersonalization->resolveView(
            $user,
            $request->query('view'),
        );
        $dashboardView = $viewResolution['view'];

        $defaultFilter = $this->dashboardPersonalization->defaultFilterFor($user, $dashboardView);
        $filter = $request->string('filter')->toString() ?: $defaultFilter;
        $availableFilters = $this->dashboardPersonalization->availableFiltersFor($user);

        if (! in_array($filter, $availableFilters, true)) {
            $filter = $defaultFilter;
        }

        if ($viewResolution['redirect']) {
            $redirect = $this->dashboardPersonalization->redirectToResolvedView(
                $request,
                $user,
                $dashboardView,
                $filter,
            );

            if ($redirect !== null) {
                return $redirect;
            }
        }

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $dashboardView, $filter);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($dashboardView);

        $canManageTransactions = $user->hasAnyRole([
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $reopenQuickCreate = (bool) session('reopen_quick_create', false);

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'reopenQuickCreate' => $reopenQuickCreate,
            'recentServiceCases' => $user->can('incidents.view')
                && $this->dashboardPersonalization->showsServiceCasesPanel($dashboardView)
                ? $this->dashboardService->recentServiceCases(
                    $filter,
                    $this->dashboardService->serviceCaseLimitForFilter($filter),
                    $assignedTo,
                    $prioritizeRecentAssignments,
                )
                : collect(),
            'recentActivity' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivity()
                : collect(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $filter,
            'dashboardView' => $dashboardView,
            'dashboardModules' => $this->dashboardPersonalization->availableModulesFor($user),
            'showsModuleNavigation' => $this->dashboardPersonalization->showsModuleNavigation($user),
            'serviceCasePanelTitle' => $this->dashboardPersonalization->serviceCasePanelTitle($dashboardView),
            'availableServiceCaseFilters' => $availableFilters,
            'assignedToScope' => $assignedTo,
            'canManageTransactions' => $canManageTransactions,
            'canReassignServiceCases' => $user->can('incidents.update'),
            'canCreateRemarks' => $user->can('create', \App\Models\Remark::class),
            'canShowServiceCaseActions' => $user->hasAnyRole([
                \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
                \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            ]) || $user->can('create', \App\Models\Remark::class) || $user->can('incidents.update'),
            'enabledProducts' => app(\App\Services\SettingService::class)->enabledProductNames(),
            'enabledSources' => app(\App\Services\SettingService::class)->enabledSources(),
            'dashboardLiveMode' => config('dashboard.live_mode', 'auto'),
            'dashboardPollIntervalMs' => config('dashboard.poll_interval_ms', 30000),
            'reverbConfigured' => config('broadcasting.default') === 'reverb'
                && config('broadcasting.connections.reverb.key'),
        ]);
    }
}
