<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardServiceCaseController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
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
}
