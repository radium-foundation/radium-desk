<?php

namespace App\Http\Middleware;

use App\Services\Operations\TeamMemberActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTeamMemberActivity
{
    public function __construct(
        private readonly TeamMemberActivityService $activityService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user !== null && $request->isMethodSafe()) {
            $this->activityService->recordSystemActivity($user);
        }

        return $response;
    }
}
