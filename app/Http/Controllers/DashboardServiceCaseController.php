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
}
