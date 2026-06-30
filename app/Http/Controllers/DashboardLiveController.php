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
        $viewResolution = $this->dashboardPersonalization->resolveView(
            $user,
            $request->query('view'),
        );
        $dashboardView = $viewResolution['view'];

        if ($this->dashboardPersonalization->showsHardwareOrdersPanel($dashboardView)) {
            $stats = $this->dashboardService->statsFor($user);

            return response()->json([
                'kpi_strip_html' => $this->dashboardService->renderKpiStrip($stats),
                'online_count' => $stats['online_count'],
                'online_users' => $this->dashboardService->onlineUsersPayload($stats),
                'service_case_filter_counts' => [],
                'service_cases_empty' => true,
                'service_cases_empty_html' => '',
                'rows' => [],
                'incident_ids' => [],
            ]);
        }

        $defaultFilter = $this->dashboardPersonalization->defaultFilterFor($user, $dashboardView);
        $filter = $request->string('filter')->toString() ?: $defaultFilter;
        $availableFilters = $this->dashboardPersonalization->availableFiltersFor($user);

        if (! in_array($filter, $availableFilters, true)) {
            $filter = $defaultFilter;
        }

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $dashboardView, $filter);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($dashboardView);
        $pageSize = $this->dashboardService->serviceCasePageSize();
        $limit = max($pageSize, min($request->integer('limit', $pageSize), 500));

        return DB::transaction(function () use ($user, $filter, $assignedTo, $prioritizeRecentAssignments, $limit): JsonResponse {
            $filterCounts = $user->can('incidents.view')
                ? $this->dashboardService->serviceCaseFilterCounts($assignedTo, $user)
                : [];

            $serviceCasesPayload = $user->can('incidents.view')
                ? $this->dashboardService->serviceCasesPayload(
                    $user,
                    $filter,
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
