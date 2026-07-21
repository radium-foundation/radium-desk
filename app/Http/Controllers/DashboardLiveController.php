<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardLiveRowVisibilityService;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardLiveController extends Controller
{
    private const LIVE_ROWS_MAX_IDS = 100;

    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
        private readonly DashboardLiveRowVisibilityService $liveRowVisibility,
    ) {}

    public function rows(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('incidents.view')) {
            return response()->json([
                'rows' => [],
                'remove_incident_ids' => [],
            ]);
        }

        $incidentIds = collect($request->query('ids', $request->input('ids', [])))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->take(self::LIVE_ROWS_MAX_IDS)
            ->all();

        $legacyView = $request->query('view');
        $legacyFilter = $request->query('filter');
        $requestedQueue = $request->query('queue');

        $queueResolution = $this->dashboardPersonalization->resolveQueue(
            $user,
            is_string($requestedQueue) ? $requestedQueue : null,
            is_string($legacyView) ? $legacyView : null,
            is_string($legacyFilter) ? $legacyFilter : null,
        );

        $payload = $this->liveRowVisibility->liveRowsPayload(
            $incidentIds,
            $user,
            $queueResolution['queue'],
        );

        return response()->json($payload);
    }

    public function refresh(Request $request): JsonResponse
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

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $operationQueue);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($operationQueue);
        $pageSize = $this->dashboardService->serviceCasePageSize();
        $limit = max($pageSize, min($request->integer('limit', $pageSize), 500));

        return DB::transaction(function () use ($user, $serviceCaseFilter, $assignedTo, $prioritizeRecentAssignments, $limit, $operationQueue, $requestedQueue, $legacyView, $legacyFilter): JsonResponse {
            $metrics = $this->dashboardService->liveMetricsFor(
                $user,
                is_string($requestedQueue) ? $requestedQueue : null,
                is_string($legacyView) ? $legacyView : null,
                is_string($legacyFilter) ? $legacyFilter : null,
            );
            $filterCounts = $metrics['service_case_filter_counts'];

            $serviceCasesPayload = $user->can('incidents.view')
                ? $this->dashboardService->serviceCasesPayload(
                    $user,
                    $serviceCaseFilter,
                    $assignedTo,
                    $prioritizeRecentAssignments,
                    $limit,
                    filterCounts: $filterCounts,
                    dashboardOperationQueue: $operationQueue,
                )
                : [
                    'rows' => [],
                    'incident_ids' => collect(),
                    'service_cases_empty' => true,
                    'service_cases_empty_html' => view('dashboard.partials.service-cases-empty')->render(),
                    'total_count' => 0,
                    'has_more' => false,
                    'loaded_count' => 0,
                ];

            return response()->json([
                'kpi_strip_html' => $metrics['kpi_strip_html'],
                'next_appointment' => $metrics['next_appointment'],
                'online_count' => $metrics['online_count'],
                'online_users' => $metrics['online_users'],
                'service_case_filter_counts' => $filterCounts,
                'service_cases_empty' => $serviceCasesPayload['service_cases_empty'],
                'service_cases_empty_html' => $serviceCasesPayload['service_cases_empty_html'],
                'rows' => $serviceCasesPayload['rows'],
                'incident_ids' => $serviceCasesPayload['incident_ids'],
                'total_count' => $serviceCasesPayload['total_count'],
                'has_more' => $serviceCasesPayload['has_more'],
                'loaded_count' => $serviceCasesPayload['loaded_count'],
            ]);
        });
    }
}
