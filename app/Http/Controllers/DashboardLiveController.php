<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardLiveController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

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

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $operationQueue);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($operationQueue);
        $pageSize = $this->dashboardService->serviceCasePageSize();
        $limit = max($pageSize, min($request->integer('limit', $pageSize), 500));

        return DB::transaction(function () use ($user, $operationQueue, $assignedTo, $prioritizeRecentAssignments, $limit): JsonResponse {
            $filterCounts = $user->can('incidents.view')
                ? $this->dashboardService->serviceCaseFilterCounts($assignedTo, $user)
                : [];

            $serviceCasesPayload = $user->can('incidents.view')
                ? $this->dashboardService->serviceCasesPayload(
                    $user,
                    $operationQueue,
                    $assignedTo,
                    $prioritizeRecentAssignments,
                    $limit,
                    filterCounts: $filterCounts,
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
            $stats = $this->dashboardService->statsFor($user);

            return response()->json([
                'kpi_strip_html' => $this->dashboardService->renderKpiStrip($stats),
                'online_count' => $stats['online_count'],
                'online_users' => $this->dashboardService->onlineUsersPayload($stats),
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
