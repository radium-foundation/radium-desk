<?php

namespace App\Http\Controllers;

use App\Services\Realtime\RealtimeConnectionStatusService;
use App\Services\Realtime\RealtimeConnectionTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RealtimeAdminActionsController extends Controller
{
    public function test(Request $request, RealtimeConnectionTestService $testService): JsonResponse
    {
        $this->authorizeSystemSettings($request);

        $result = $testService->test();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function forceReconnect(Request $request, RealtimeConnectionStatusService $connectionStatus): JsonResponse
    {
        $this->authorizeSystemSettings($request);

        $connectionStatus->requestForceReconnect();

        return response()->json([
            'ok' => true,
            'message' => 'Force reconnect requested. Dashboard clients will reconnect on next load or visibility refresh.',
        ]);
    }

    public function resetStatus(Request $request, RealtimeConnectionStatusService $connectionStatus): RedirectResponse
    {
        $this->authorizeSystemSettings($request);

        $connectionStatus->reset();

        return redirect()
            ->route('admin.system-settings.index')
            ->with('status', 'realtime-connection-status-reset');
    }

    private function authorizeSystemSettings(Request $request): void
    {
        abort_unless($request->user()?->can('update', \App\Models\SystemSetting::class), 403);
    }
}
