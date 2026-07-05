<?php

namespace App\Http\Controllers;

use App\Enums\PerformancePeriod;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\SmartAssignmentFeedbackMetricsService;
use App\Services\Operations\TeamPerformanceMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class MyPerformanceController extends Controller
{
    public function __construct(
        private readonly TeamPerformanceMetricsService $metricsService,
        private readonly SmartAssignmentFeedbackMetricsService $feedbackMetricsService,
        private readonly OperationsRoleService $roleService,
    ) {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            abort_unless($user !== null && $this->roleService->isTeamMember($user), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $period = PerformancePeriod::tryFrom((string) $request->query('period'))
            ?? PerformancePeriod::ThisMonth;
        $customStart = filled($request->query('start_date'))
            ? Carbon::parse((string) $request->query('start_date'))->startOfDay()
            : null;
        $customEnd = filled($request->query('end_date'))
            ? Carbon::parse((string) $request->query('end_date'))->endOfDay()
            : null;

        return view('my-performance.index', [
            'period' => $period,
            'customStart' => $customStart?->toDateString(),
            'customEnd' => $customEnd?->toDateString(),
            'metrics' => $this->metricsService->metricsFor($user, $period, $customStart, $customEnd),
            'assignmentFeedback' => $this->feedbackMetricsService->feedbackFor($user),
        ]);
    }
}
