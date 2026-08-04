<?php

namespace App\Http\Controllers;

use App\Data\RecentActivityStreams;
use App\Data\TeamActivityPanel;
use App\Services\Dashboard\OperationsWorkspacePanelService;
use App\Services\Dashboard\OperationsWorkspaceResolver;
use App\Services\Dashboard\TeamActivityPanelService;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\Realtime\RealtimeRuntimeConfig;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
        private readonly OperationsWorkspaceResolver $operationsWorkspace,
        private readonly OperationsWorkspacePanelService $operationsWorkspacePanel,
        private readonly TeamActivityPanelService $teamActivityPanelService,
        private readonly PerformanceRuntimeConfig $performanceRuntime,
        private readonly RealtimeRuntimeConfig $realtimeRuntime,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $workspace = $this->operationsWorkspace->resolve($user, $request);
        $operationQueue = $workspace['operation_queue'];
        $serviceCaseFilter = $workspace['service_case_filter'];
        $isEmbedded = ($workspace['kind'] ?? 'case_queue') === 'embedded'
            && $this->operationsWorkspace->phase2EmbedEnabled();

        if ($workspace['redirect'] && ! $isEmbedded) {
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

        $embeddedPanelHtml = null;
        if ($isEmbedded) {
            try {
                $embeddedPanelHtml = $this->operationsWorkspacePanel->renderEmbedded($user, $request)['panel_html'];
            } catch (Throwable) {
                $isEmbedded = false;
            }
        }

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'openCustomer360IncidentId' => $openCustomer360IncidentId,
            'openCustomer360Reference' => $openCustomer360Reference,
            'openCustomer360MoreMenu' => $openCustomer360MoreMenu,
            'recentServiceCases' => $recentServiceCases,
            'serviceCaseFilterCounts' => $serviceCaseFilterCounts,
            'serviceCaseTotalCount' => $serviceCaseFilterCounts[$serviceCaseFilter] ?? $recentServiceCases->count(),
            'serviceCaseHasMore' => $recentServiceCases->count() < ($serviceCaseFilterCounts[$serviceCaseFilter] ?? $recentServiceCases->count()),
            'teamActivityEnabled' => (bool) config('dashboard-team-activity.enabled', true),
            'teamActivityPanel' => $user->can('teamActivity.view') && config('dashboard-team-activity.enabled', true)
                ? $this->teamActivityPanelService->build()
                : TeamActivityPanel::empty(),
            'recentActivityStreams' => $user->can('audit-logs.view') && ! config('dashboard-team-activity.enabled', true)
                ? $this->dashboardService->recentActivityStreams($user)
                : RecentActivityStreams::empty(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $serviceCaseFilter,
            'operationQueue' => $operationQueue,
            'operationsWorkspace' => $workspace['workspace'],
            'operationsWorkspaceKind' => $isEmbedded ? 'embedded' : 'case_queue',
            'operationsWorkspaceSoftSwitch' => $this->operationsWorkspace->softSwitchEnabled(),
            'operationsWorkspacePhase2Embed' => $this->operationsWorkspace->phase2EmbedEnabled(),
            'embeddedWorkspacePanelHtml' => $embeddedPanelHtml,
            'dashboardLiveScope' => $workspace['live_scope'],
            'operationQueues' => $this->dashboardPersonalization->queueMetaFor($user),
            'availableOperationQueues' => $this->dashboardPersonalization->availableQueuesFor($user),
            'showsQueueNavigation' => $this->dashboardPersonalization->showsQueueNavigation($user),
            'serviceCasePanelTitle' => $workspace['case_panel_title'] ?? (
                $isEmbedded
                    ? $this->operationsWorkspace->panelTitle($operationQueue, $serviceCaseFilter)
                    : $workspace['panel_title']
            ),
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
