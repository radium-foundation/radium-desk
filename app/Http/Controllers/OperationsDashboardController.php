<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiumBoxBatchRecoveryRequest;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\Operations\OperationsDashboardService;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OperationsDashboardController extends Controller
{
    public function __construct(
        private readonly OperationsDashboardService $dashboardService,
        private readonly OperationsAdvisorService $advisorService,
        private readonly RadiumBoxSyncRecoveryService $radiumBoxRecoveryService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('operations-dashboard.view'), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        return view('admin.operations.index', [
            'dashboard' => $this->dashboardService->dashboardData(),
            'advisorInsights' => $this->advisorService->platformInsights(),
        ]);
    }

    public function live(Request $request): JsonResponse
    {
        $dashboard = $this->dashboardService->dashboardData();
        $advisorInsights = $this->advisorService->platformInsights();

        return response()->json([
            'generated_at' => $dashboard->generatedAt->toIso8601String(),
            'html' => [
                'advisor_insights' => view('admin.operations.partials.advisor-insights', [
                    'insights' => $advisorInsights,
                ])->render(),
                'system_health' => view('admin.operations.partials.system-health', [
                    'components' => $dashboard->systemHealth,
                ])->render(),
                'notification_metrics' => view('admin.operations.partials.notification-metrics', [
                    'metrics' => $dashboard->notificationMetrics,
                ])->render(),
                'automation_metrics' => view('admin.operations.partials.automation-metrics', [
                    'metrics' => $dashboard->automationMetrics,
                ])->render(),
                'queue_metrics' => view('admin.operations.partials.queue-metrics', [
                    'metrics' => $dashboard->queueMetrics,
                ])->render(),
                'integration_health' => view('admin.operations.partials.integration-health', [
                    'cards' => $dashboard->integrationHealth,
                ])->render(),
                'radiumbox_health' => view('admin.operations.partials.radiumbox-health', [
                    'health' => $dashboard->radiumBoxHealth,
                ])->render(),
                'recent_notification_failures' => view('admin.operations.partials.recent-notification-failures', [
                    'failures' => $dashboard->recentNotificationFailures,
                ])->render(),
                'recent_automation_activity' => view('admin.operations.partials.recent-automation-activity', [
                    'activities' => $dashboard->recentAutomationActivity,
                ])->render(),
                'team_availability' => view('admin.operations.partials.team-availability', [
                    'members' => $dashboard->teamAvailability,
                ])->render(),
            ],
        ]);
    }

    public function batchRecoverRadiumBox(RadiumBoxBatchRecoveryRequest $request): JsonResponse
    {
        $orderIds = array_map('intval', $request->validated('order_ids'));
        $result = $this->radiumBoxRecoveryService->recoverOrders($orderIds);
        $dashboard = $this->dashboardService->dashboardData(useCache: false);

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Recovery dispatched for %d order(s). %d skipped.',
                $result->recovered,
                $result->skipped,
            ),
            'result' => [
                'requested' => $result->requested,
                'recovered' => $result->recovered,
                'skipped' => $result->skipped,
            ],
            'html' => [
                'radiumbox_health' => view('admin.operations.partials.radiumbox-health', [
                    'health' => $dashboard->radiumBoxHealth,
                ])->render(),
            ],
        ]);
    }
}
