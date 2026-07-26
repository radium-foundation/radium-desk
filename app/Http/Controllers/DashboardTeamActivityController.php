<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\TeamActivityPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardTeamActivityController extends Controller
{
    public function __construct(
        private readonly TeamActivityPanelService $teamActivityPanelService,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('audit-logs.view') || ! config('dashboard-team-activity.enabled', true)) {
            return response()->json([
                'html' => null,
                'empty' => true,
                'generated_at' => now()->toIso8601String(),
                'agent_count' => 0,
            ]);
        }

        $expanded = $request->input('expanded', []);

        if (! is_array($expanded)) {
            $expanded = [];
        }

        $panel = $this->teamActivityPanelService->build(
            array_map(static fn (mixed $id): int => (int) $id, $expanded),
        );

        if ($panel->empty) {
            return response()->json([
                'html' => null,
                'empty' => true,
                'generated_at' => now()->toIso8601String(),
                'agent_count' => 0,
            ]);
        }

        return response()->json([
            'html' => $this->teamActivityPanelService->render($panel),
            'empty' => false,
            'generated_at' => now()->toIso8601String(),
            'agent_count' => $panel->agentCount(),
        ]);
    }
}
