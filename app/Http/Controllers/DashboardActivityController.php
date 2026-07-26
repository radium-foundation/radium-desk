<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardActivityController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('audit-logs.view')) {
            return response()->json([
                'html' => null,
                'empty' => true,
            ]);
        }

        $streams = $this->dashboardService->recentActivityStreams($user);

        if ($streams->isEmpty()) {
            return response()->json([
                'html' => null,
                'empty' => true,
            ]);
        }

        return response()->json([
            'html' => view('dashboard.partials.recent-activity-feed', [
                'streams' => $streams,
            ])->render(),
            'empty' => false,
        ]);
    }
}
