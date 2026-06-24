<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'recentServiceCases' => $user->can('incidents.view')
                ? $this->dashboardService->recentServiceCases()
                : collect(),
            'recentActivity' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivity()
                : collect(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
        ]);
    }
}
