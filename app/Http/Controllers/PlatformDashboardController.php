<?php

namespace App\Http\Controllers;

use App\Services\Performance\PerformanceRuntimeConfig;
use App\Services\Platform\Health\PlatformOverallHealthService;
use App\Services\Platform\PlatformDashboardService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PlatformDashboardController extends Controller
{
    public function __construct(
        private readonly PlatformDashboardService $dashboardService,
        private readonly PlatformZoneRegistry $zoneRegistry,
        private readonly PerformanceRuntimeConfig $performanceRuntime,
        private readonly PlatformOverallHealthService $overallHealth,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->can('platform-dashboard.view'), 403);

        $zones = $this->dashboardService->zoneSnapshots($request->user());

        return view('admin.platform.index', [
            'zones' => $zones,
            'overallHealth' => $this->overallHealth->summarize(useCache: true),
            'platformPollIntervalSeconds' => $this->performanceRuntime->executiveDashboardPollIntervalSeconds(),
        ]);
    }

    public function zone(Request $request, string $zone): JsonResponse
    {
        abort_unless($request->user()?->can('platform-dashboard.view'), 403);
        abort_unless($this->zoneRegistry->has($zone), 404);

        $provider = $this->zoneRegistry->get($zone);
        abort_unless($provider->authorize($request->user()), 403);

        $snapshot = $provider->refresh($request->user());

        return response()->json([
            'key' => $snapshot->key,
            'status' => $snapshot->status->value,
            'status_label' => $snapshot->statusLabel,
            'updated_at' => $snapshot->updatedAt?->toIso8601String(),
            'html' => $snapshot->html,
            'summary' => $snapshot->summary,
            'from_cache' => $snapshot->fromCache,
            'available' => $snapshot->available,
        ]);
    }

    public function expand(Request $request, string $zone, string $item): JsonResponse
    {
        abort_unless($request->user()?->can('platform-dashboard.view'), 403);
        abort_unless($this->zoneRegistry->has($zone), 404);

        $provider = $this->zoneRegistry->get($zone);
        abort_unless($provider->authorize($request->user()), 403);

        $result = $provider->expand($request->user(), $item);

        if ($result === null) {
            abort(404);
        }

        return response()->json($result->toArray());
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
