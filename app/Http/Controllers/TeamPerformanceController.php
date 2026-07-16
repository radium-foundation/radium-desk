<?php

namespace App\Http\Controllers;

use App\Enums\PerformancePeriod;
use App\Services\Operations\IraPerformanceInsightsService;
use App\Services\Operations\TeamPerformanceMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class TeamPerformanceController extends Controller
{
    public function __construct(
        private readonly TeamPerformanceMetricsService $metricsService,
        private readonly IraPerformanceInsightsService $insightsService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()?->can('team-performance.view'), 403);

            return $next($request);
        });
    }

    public function index(Request $request): View
    {
        $period = PerformancePeriod::tryFrom((string) $request->query('period'))
            ?? PerformancePeriod::ThisMonth;
        $customStart = filled($request->query('start_date'))
            ? Carbon::parse((string) $request->query('start_date'))->startOfDay()
            : null;
        $customEnd = filled($request->query('end_date'))
            ? Carbon::parse((string) $request->query('end_date'))->endOfDay()
            : null;

        return view('admin.workforce.performance.index', [
            'period' => $period,
            'customStart' => $customStart?->toDateString(),
            'customEnd' => $customEnd?->toDateString(),
            'teamMetrics' => $this->metricsService->teamMetrics($period, $customStart, $customEnd),
            'insights' => $this->insightsService->insights($period, $customStart, $customEnd),
        ]);
    }
}
