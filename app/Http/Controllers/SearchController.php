<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearchService $globalSearchService,
    ) {}

    public function search(Request $request): JsonResponse|RedirectResponse
    {
        $query = $request->string('q')->trim()->toString();

        if ($request->expectsJson() || $request->ajax()) {
            return $this->jsonResponse($request, $query);
        }

        return redirect()->route('dashboard');
    }

    private function jsonResponse(Request $request, string $query): JsonResponse
    {
        $user = $request->user();

        if ($query === '') {
            return response()->json([
                'match_count' => 0,
                'results' => [],
                'incident_ids' => [],
            ]);
        }

        if (! $user->can('incidents.view')) {
            return response()->json([
                'match_count' => 0,
                'results' => [],
                'incident_ids' => [],
            ]);
        }

        $results = $this->globalSearchService->search($user, $query);
        $payload = $results->map(fn ($result) => $result->toArray())->values();

        return response()->json([
            'match_count' => $payload->count(),
            'results' => $payload,
            'incident_ids' => $payload
                ->where('type', 'service_case')
                ->pluck('incident_id')
                ->values(),
        ]);
    }
}
