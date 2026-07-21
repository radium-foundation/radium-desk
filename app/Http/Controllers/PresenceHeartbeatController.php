<?php

namespace App\Http\Controllers;

use App\Enums\PresenceActivityType;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Performance\PerformanceRuntimeConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PresenceHeartbeatController extends Controller
{
    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
        private readonly PerformanceRuntimeConfig $performanceRuntime,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user !== null, 401);
        abort_unless($this->roleService->isTeamMember($user), 403);

        if ($this->presenceEngine->shouldForceLogout($user)) {
            $this->presenceEngine->forceLogoutUser($user, $request);

            return response()->json([
                'message' => 'Your session ended due to inactivity.',
                'logout' => true,
            ], 401);
        }

        $this->presenceEngine->recordActivity($user, PresenceActivityType::Heartbeat);

        $snapshot = $this->presenceEngine->snapshotFor($user);

        return response()->json([
            'presence' => $snapshot,
            'next_heartbeat_seconds' => $this->performanceRuntime->presenceHeartbeatIntervalSeconds(),
        ]);
    }
}
