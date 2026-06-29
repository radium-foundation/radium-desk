<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\DashboardService;
use App\Services\UniversalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly UniversalSearchService $universalSearchService,
        private readonly DashboardService $dashboardService,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

    public function search(Request $request): JsonResponse|RedirectResponse
    {
        $query = $request->string('q')->trim()->toString();

        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonResponse($request, $query);
        }

        return redirect()->route('dashboard', array_filter([
            'q' => $query !== '' ? $query : null,
            'view' => $request->query('view'),
        ]));
    }

    private function jsonResponse(Request $request, string $query): JsonResponse
    {
        $user = $request->user();

        if ($query === '') {
            return response()->json([
                'match_count' => 0,
                'rows' => [],
                'incident_ids' => [],
            ]);
        }

        if (! $user->can('incidents.view')) {
            return response()->json([
                'match_count' => 0,
                'rows' => [],
                'incident_ids' => [],
            ]);
        }

        $viewResolution = $this->dashboardPersonalization->resolveView(
            $user,
            $request->query('view'),
        );
        $assignedTo = $this->dashboardPersonalization->scopesServiceCasesToAssignee($viewResolution['view'])
            ? $user
            : null;

        $matches = $this->universalSearchService->search($user, $query, $assignedTo);

        $rows = $matches->map(function ($serviceCase) use ($user) {
            return [
                'incident_id' => $serviceCase->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->dashboardService->serviceCaseRowViewData($serviceCase, $user),
                )->render(),
            ];
        })->values();

        return response()->json([
            'match_count' => $matches->count(),
            'rows' => $rows,
            'incident_ids' => $matches->pluck('id')->values(),
        ]);
    }
}
