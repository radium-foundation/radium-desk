<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardServiceCaseController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

    public function row(Request $request, Incident $incident): JsonResponse
    {
        $this->authorize('view', $incident);

        $user = $request->user();

        return response()->json([
            'incident_id' => $incident->id,
            'html' => view(
                'dashboard.partials.service-case-row',
                $this->dashboardService->serviceCaseRowViewData(
                    $incident->load(['order.transactionAssigner', 'creator', 'assignee']),
                    $user,
                ),
            )->render(),
        ]);
    }

    public function searchRows(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('incidents.view')) {
            return response()->json([
                'rows' => [],
                'service_cases_empty' => true,
                'service_cases_empty_html' => '',
                'incident_ids' => [],
            ]);
        }

        $incidentIds = collect($request->input('ids', []))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->take(20)
            ->all();

        $rows = $this->dashboardService->serviceCaseRowsForSearch($incidentIds, $user);

        return response()->json([
            'rows' => $rows,
            'service_cases_empty' => $rows->isEmpty(),
            'service_cases_empty_html' => view('dashboard.partials.service-cases-empty')->render(),
            'incident_ids' => $rows->pluck('incident_id')->values(),
        ]);
    }

    public function loadMore(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('incidents.view')) {
            return response()->json([
                'rows' => [],
                'service_cases_empty' => true,
                'service_cases_empty_html' => '',
                'incident_ids' => [],
                'total_count' => 0,
                'has_more' => false,
                'loaded_count' => 0,
            ]);
        }

        $viewResolution = $this->dashboardPersonalization->resolveView(
            $user,
            $request->query('view'),
        );
        $dashboardView = $viewResolution['view'];
        $defaultFilter = $this->dashboardPersonalization->defaultFilterFor($user, $dashboardView);
        $filter = $request->string('filter')->toString() ?: $defaultFilter;
        $availableFilters = $this->dashboardPersonalization->availableFiltersFor($user);

        if (! in_array($filter, $availableFilters, true)) {
            $filter = $defaultFilter;
        }

        $assignedTo = $this->dashboardPersonalization->resolveAssignedToScope($user, $dashboardView, $filter);
        $prioritizeRecentAssignments = $this->dashboardPersonalization->prioritizesRecentAssignments($dashboardView);
        $pageSize = $this->dashboardService->serviceCasePageSize();
        $offset = max(0, $request->integer('offset', 0));

        $payload = $this->dashboardService->serviceCasesPayload(
            $user,
            $filter,
            $assignedTo,
            $prioritizeRecentAssignments,
            $pageSize,
            $offset,
        );

        return response()->json($payload);
    }
}
