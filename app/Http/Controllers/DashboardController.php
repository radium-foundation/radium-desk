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
        $legacyView = $request->query('view');
        $legacyFilter = $request->query('filter');
        $requestedQueue = $request->query('queue');

        $queueResolution = $this->dashboardPersonalization->resolveQueue(
            $user,
            is_string($requestedQueue) ? $requestedQueue : null,
            is_string($legacyView) ? $legacyView : null,
            is_string($legacyFilter) ? $legacyFilter : null,
        );
        $operationQueue = $queueResolution['queue'];

        if ($queueResolution['redirect']) {
            $redirect = $this->dashboardPersonalization->redirectToResolvedQueue(
                $request,
                $user,
                $operationQueue,
            );

            if ($redirect !== null) {
                return $redirect;
            }
        }

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $operationQueue);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($operationQueue);

        $serviceCaseFilterCounts = $user->can('incidents.view')
            ? $this->dashboardService->serviceCaseFilterCounts($assignedTo, $user)
            : [];

        $pageSize = $this->dashboardService->serviceCasePageSize();
        $recentServiceCases = $user->can('incidents.view')
            ? $this->dashboardService->recentServiceCases(
                $operationQueue,
                $pageSize,
                $assignedTo,
                $prioritizeRecentAssignments,
            )
            : collect();

        $canManageTransactions = $user->hasAnyRole([
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ]);

        $reopenQuickCreate = (bool) session('reopen_quick_create', false);

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'reopenQuickCreate' => $reopenQuickCreate,
            'recentServiceCases' => $recentServiceCases,
            'serviceCaseFilterCounts' => $serviceCaseFilterCounts,
            'serviceCaseTotalCount' => $serviceCaseFilterCounts[$operationQueue] ?? $recentServiceCases->count(),
            'serviceCaseHasMore' => $recentServiceCases->count() < ($serviceCaseFilterCounts[$operationQueue] ?? $recentServiceCases->count()),
            'recentActivity' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivity()
                : collect(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $operationQueue,
            'operationQueue' => $operationQueue,
            'operationQueues' => $this->dashboardPersonalization->queueMetaFor($user),
            'availableOperationQueues' => $this->dashboardPersonalization->availableQueuesFor($user),
            'showsQueueNavigation' => $this->dashboardPersonalization->showsQueueNavigation($user),
            'serviceCasePanelTitle' => $this->dashboardPersonalization->serviceCasePanelTitle($operationQueue),
            'assignedToScope' => $assignedTo,
            'canManageTransactions' => $canManageTransactions,
            'canReassignServiceCases' => $user->can('incidents.update'),
            'canCreateRemarks' => $user->can('create', \App\Models\Remark::class),
            'canShowServiceCaseActions' => $user->hasAnyRole([
                \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
                \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
                \Database\Seeders\RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
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
