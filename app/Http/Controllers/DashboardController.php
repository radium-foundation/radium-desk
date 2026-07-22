<?php

namespace App\Http\Controllers;

use App\Data\RecentActivityStreams;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\Realtime\RealtimeRuntimeConfig;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
        private readonly PerformanceRuntimeConfig $performanceRuntime,
        private readonly RealtimeRuntimeConfig $realtimeRuntime,
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
        $serviceCaseFilter = $this->dashboardPersonalization->resolveServiceCaseFilter(
            $user,
            is_string($requestedQueue) ? $requestedQueue : null,
            is_string($legacyView) ? $legacyView : null,
            is_string($legacyFilter) ? $legacyFilter : null,
        );

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

        $openCustomer360IncidentId = $request->query('open_customer_360', session('open_customer_360_incident_id'));
        $openCustomer360MoreMenu = $request->boolean('open_more_menu');
        $openCustomer360Reference = $request->query('open_customer_360_reference', session('service_case_reference'));

        $serviceCaseFilterCounts = $user->can('incidents.view')
            ? $this->dashboardService->serviceCaseFilterCounts($assignedTo, $user)
            : [];

        $pageSize = $this->dashboardService->serviceCasePageSize();
        $recentServiceCases = $user->can('incidents.view')
            ? $this->dashboardService->recentServiceCases(
                $serviceCaseFilter,
                $pageSize,
                $assignedTo,
                $prioritizeRecentAssignments,
            )
            : collect();

        $canManageTransactions = $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ]);

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'openCustomer360IncidentId' => $openCustomer360IncidentId,
            'openCustomer360Reference' => $openCustomer360Reference,
            'openCustomer360MoreMenu' => $openCustomer360MoreMenu,
            'recentServiceCases' => $recentServiceCases,
            'serviceCaseFilterCounts' => $serviceCaseFilterCounts,
            'serviceCaseTotalCount' => $serviceCaseFilterCounts[$serviceCaseFilter] ?? $recentServiceCases->count(),
            'serviceCaseHasMore' => $recentServiceCases->count() < ($serviceCaseFilterCounts[$serviceCaseFilter] ?? $recentServiceCases->count()),
            'recentActivityStreams' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivityStreams($user)
                : RecentActivityStreams::empty(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $serviceCaseFilter,
            'operationQueue' => $operationQueue,
            'dashboardLiveScope' => $this->dashboardPersonalization->scopeForQueue($operationQueue, $user),
            'operationQueues' => $this->dashboardPersonalization->queueMetaFor($user),
            'availableOperationQueues' => $this->dashboardPersonalization->availableQueuesFor($user),
            'showsQueueNavigation' => $this->dashboardPersonalization->showsQueueNavigation($user),
            'serviceCasePanelTitle' => match ($serviceCaseFilter) {
                'needs_attention' => 'Needs Attention',
                'my_attention' => 'My Attention',
                default => $this->dashboardPersonalization->serviceCasePanelTitle($operationQueue),
            },
            'assignedToScope' => $assignedTo,
            'canManageTransactions' => $canManageTransactions,
            'enabledProducts' => app(SettingService::class)->enabledProductNames(),
            'enabledSources' => app(SettingService::class)->enabledSources(),
            ...$this->realtimeRuntime->forDashboardBlade(),
            'debugModeEnabled' => $this->realtimeRuntime->debugModeEnabled()
                && $user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN),
            'customer360TimelinePollIntervalMs' => $this->performanceRuntime->customer360TimelinePollIntervalMs(),
            'customer360DeviceSyncPollIntervalMs' => $this->performanceRuntime->customer360DeviceSyncPollIntervalMs(),
            'agentReminderIntervalSeconds' => $this->performanceRuntime->agentReminderIntervalSeconds(),
        ]);
    }
}
