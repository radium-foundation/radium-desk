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

        $assignedTo = $this->dashboardPersonalization->scopesServiceCasesToAssignee($dashboardView)
            ? $user
            : null;
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($dashboardView);

        return DB::transaction(function () use ($user, $filter, $assignedTo, $prioritizeRecentAssignments): JsonResponse {
            $recentServiceCases = $user->can('incidents.view')
                ? $this->dashboardService->recentServiceCases(
                    $filter,
                    $this->dashboardService->serviceCaseLimitForFilter($filter),
                    $assignedTo,
                    $prioritizeRecentAssignments,
                )
                : collect();
            $stats = $this->dashboardService->statsFor($user);

            $rows = $recentServiceCases->map(function ($serviceCase) use ($user) {
                return [
                    'incident_id' => $serviceCase->id,
                    'html' => view(
                        'dashboard.partials.service-case-row',
                        $this->dashboardService->serviceCaseRowViewData($serviceCase, $user),
                    )->render(),
                ];
            })->values();

            return response()->json([
                'kpi_strip_html' => $this->dashboardService->renderKpiStrip($stats),
                'online_count' => $stats['online_count'],
                'online_users' => $this->dashboardService->onlineUsersPayload($stats),
                'service_case_filter_counts' => $this->dashboardService->serviceCaseFilterCounts($assignedTo),
                'service_cases_empty' => $recentServiceCases->isEmpty(),
                'service_cases_empty_html' => view('dashboard.partials.service-cases-empty')->render(),
                'rows' => $rows,
                'incident_ids' => $recentServiceCases->pluck('id')->values(),
            ]);
        });
    }
}
