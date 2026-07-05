<?php

namespace App\Http\Middleware;

use App\Enums\PresenceActivityType;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\TeamMemberActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTeamMemberActivity
{
    public function __construct(
        private readonly TeamMemberActivityService $activityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->roleService->isTeamMember($user)) {
            if (
                ! $request->routeIs('logout')
                && $this->presenceEngine->shouldForceLogout($user)
            ) {
                $this->presenceEngine->forceLogoutUser($user, $request);

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Your session ended due to inactivity.',
                        'logout' => true,
                    ], 401);
                }

                return redirect()
                    ->route('login')
                    ->withErrors(['email' => 'Your session ended due to inactivity. Please sign in again.']);
            }
        }

        $response = $next($request);

        if ($user !== null && $request->isMethodSafe()) {
            $this->activityService->recordSystemActivity($user);
            $this->presenceEngine->recordActivity($user, PresenceActivityType::System);
        }

        return $response;
    }
}
