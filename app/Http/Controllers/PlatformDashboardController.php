<?php

namespace App\Http\Controllers;

use App\Services\Platform\PlatformDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PlatformDashboardController extends Controller
{
    public function __construct(
        private readonly PlatformDashboardService $dashboardService,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('platform-dashboard.view'), 403);

        $dashboard = $this->dashboardService->build($request->user());

        return view('admin.platform.index', [
            'dashboard' => $dashboard,
        ]);
    }

    public function showCard(Request $request, string $card): JsonResponse
    {
        abort_unless($request->user()?->can('platform-dashboard.view'), 403);

        try {
            $payload = $this->dashboardService->cardPayload($request->user(), $card);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $html = view('components.platform.card', [
            'card' => $payload,
        ])->render();

        return response()->json([
            'key' => $payload->key,
            'status' => $payload->status->value,
            'status_label' => $payload->statusLabel(),
            'generated_at' => $payload->generatedAt->toIso8601String(),
            'html' => $html,
            'payload' => $payload->toArray(),
        ]);
    }
}
