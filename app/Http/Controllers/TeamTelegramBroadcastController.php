<?php

namespace App\Http\Controllers;

use App\Enums\TeamBroadcastAudience;
use App\Http\Requests\BroadcastTeamTelegramRequest;
use App\Services\Operations\TeamTelegramBroadcastService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;

class TeamTelegramBroadcastController extends Controller
{
    public function __construct(
        private readonly TeamTelegramBroadcastService $broadcastService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN), 403);

            return $next($request);
        });
    }

    public function store(BroadcastTeamTelegramRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $audience = TeamBroadcastAudience::from((string) $validated['audience']);
        $userIds = array_map('intval', $validated['user_ids'] ?? []);

        if ($audience === TeamBroadcastAudience::Selected && $userIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one team member for a targeted broadcast.',
            ], 422);
        }

        $result = $this->broadcastService->broadcast(
            sender: $request->user(),
            message: (string) $validated['message'],
            audience: $audience,
            selectedUserIds: $userIds,
            subject: isset($validated['subject']) ? (string) $validated['subject'] : null,
        );

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Announcement sent to %d recipient(s). %d delivered.',
                $result['recipients'],
                $result['sent'],
            ),
            'result' => $result,
        ]);
    }
}
