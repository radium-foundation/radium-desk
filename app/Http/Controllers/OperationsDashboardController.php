<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiumBoxBatchRecoveryRequest;
use App\Services\Operations\IraBriefingFormatter;
use App\Services\Operations\IraOperationsBrainService;
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
        private readonly IraOperationsBrainService $iraBrainService,
        private readonly IraBriefingFormatter $iraBriefingFormatter,
        private readonly RadiumBoxSyncRecoveryService $radiumBoxRecoveryService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('operations-dashboard.view'), 403);

            return $next($request);
        });
    }

    public function index(): View
    {
        $iraBriefing = $this->iraBrainService->briefing();

        return view('admin.operations.index', [
            'dashboard' => $this->dashboardService->dashboardData(),
            'advisorInsights' => $this->advisorService->platformInsights(),
            'iraBriefing' => $iraBriefing,
            'iraBriefingFormatted' => $this->iraBriefingFormatter->format($iraBriefing),
            'iraReasoningProvider' => $this->iraBrainService->reasoningProviderName(),
        ]);
    }

    public function live(Request $request): JsonResponse
    {
        $dashboard = $this->dashboardService->dashboardData();
        $advisorInsights = $this->advisorService->platformInsights();
        $iraBriefing = $this->iraBrainService->briefing();
        $iraBriefingFormatted = $this->iraBriefingFormatter->format($iraBriefing);

        return response()->json([
            'generated_at' => $dashboard->generatedAt->toIso8601String(),
            'html' => [
                'ira_briefing' => view('admin.operations.partials.ira-briefing', [
                    'briefing' => $iraBriefing,
                    'formatted' => $iraBriefingFormatted,
                    'reasoningProvider' => $this->iraBrainService->reasoningProviderName(),
                ])->render(),
                'overview_cards' => view('admin.operations.partials.overview-cards', [
                    'briefing' => $iraBriefing,
                    'formatted' => $iraBriefingFormatted,
                    'members' => $dashboard->teamAvailability,
                    'insights' => $advisorInsights,
                ])->render(),
                'ira_briefing_details' => view('admin.operations.partials.ira-briefing-details', [
                    'briefing' => $iraBriefing,
                    'formatted' => $iraBriefingFormatted,
                ])->render(),
                'immediate_risks' => view('admin.operations.partials.immediate-risks', [
                    'briefing' => $iraBriefing,
                ])->render(),
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
                'cashfree_device_enrichment_quality' => view('admin.operations.partials.cashfree-device-enrichment-quality', [
                    'quality' => $dashboard->cashfreeDeviceEnrichmentQuality,
                ])->render(),
                'missing_serial_automation_quality' => view('admin.operations.partials.missing-serial-automation-quality', [
                    'quality' => $dashboard->missingSerialAutomationQuality,
                ])->render(),
                'support_intelligence' => view('admin.operations.partials.support-intelligence', [
                    'intelligence' => $dashboard->supportIntelligence,
                ])->render(),
                'recent_notification_failures' => view('admin.operations.partials.recent-notification-failures', [
                    'failures' => $dashboard->recentNotificationFailures,
                ])->render(),
                'recent_automation_activity' => view('admin.operations.partials.recent-automation-activity', [
                    'activities' => $dashboard->recentAutomationActivity,
                ])->render(),
                'recent_ira_messages' => view('admin.operations.partials.recent-ira-messages', [
                    'messages' => $dashboard->recentIraMessages,
                ])->render(),
                'team_availability' => view('admin.operations.partials.team-availability', [
                    'members' => $dashboard->teamAvailability,
                ])->render(),
                'team_telegram_status' => view('admin.operations.partials.team-telegram-status', [
                    'members' => $dashboard->teamTelegramStatus,
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
