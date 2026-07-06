<?php

namespace App\Http\Controllers;

use App\Http\Requests\RadiumBoxBatchRecoveryRequest;
use App\Services\Operations\IraBriefingFormatter;
use App\Services\Operations\IraOperationsBrainService;
use App\Services\Operations\OperationsAdvisorService;
use App\Services\Operations\OperationsDashboardLiveRenderer;
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
        private readonly OperationsDashboardLiveRenderer $liveRenderer,
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
        $groups = $this->resolveLiveGroups($request);
        $sections = OperationsDashboardLiveRenderer::resolveSections($groups);
        $needsIra = $this->sectionsNeedIra($sections);
        $needsAdvisor = $this->sectionsNeedAdvisor($sections);

        $dashboard = $this->dashboardService->dashboardData();
        $iraBriefing = $needsIra ? $this->iraBrainService->briefing() : null;
        $iraBriefingFormatted = $needsIra && $iraBriefing !== null
            ? $this->iraBriefingFormatter->format($iraBriefing)
            : null;
        $advisorInsights = $needsAdvisor ? $this->advisorService->platformInsights() : [];

        return response()->json([
            'generated_at' => $dashboard->generatedAt->toIso8601String(),
            'groups' => $groups,
            'html' => $this->liveRenderer->renderSections(
                $sections,
                $dashboard,
                $iraBriefing,
                $iraBriefingFormatted,
                $this->iraBrainService->reasoningProviderName(),
                $advisorInsights,
            ),
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
                'health_status' => view('admin.operations.partials.health-status-compact', [
                    'cashfreeHealth' => $dashboard->cashfreeHealth,
                    'radiumBoxHealth' => $dashboard->radiumBoxHealth,
                    'teamTelegramStatus' => $dashboard->teamTelegramStatus,
                ])->render(),
                'critical_alerts' => view('admin.operations.partials.critical-alerts', [
                    'dashboard' => $dashboard,
                    'briefing' => $this->iraBrainService->briefing(),
                ])->render(),
            ],
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function resolveLiveGroups(Request $request): ?array
    {
        $groups = $request->query('groups');

        if (! is_string($groups) || trim($groups) === '') {
            return null;
        }

        return collect(explode(',', $groups))
            ->map(fn (string $group): string => trim($group))
            ->filter(fn (string $group): bool => $group !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $sections
     */
    private function sectionsNeedIra(array $sections): bool
    {
        return collect($sections)->contains(fn (string $section): bool => in_array($section, [
            'critical_alerts',
            'overview_cards',
            'ira_briefing',
            'ira_briefing_details',
            'immediate_risks',
        ], true));
    }

    /**
     * @param  list<string>  $sections
     */
    private function sectionsNeedAdvisor(array $sections): bool
    {
        return collect($sections)->contains(fn (string $section): bool => in_array($section, [
            'overview_cards',
            'advisor_insights',
        ], true));
    }
}
