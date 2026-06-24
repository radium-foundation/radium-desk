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
        $filter = $request->string('filter')->toString() ?: 'pending_admin';

        if (! in_array($filter, ['all', 'pending_admin', 'completed', 'high_priority'], true)) {
            $filter = 'pending_admin';
        }

        $canManageTransactions = $user->hasAnyRole([
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'recentServiceCases' => $user->can('incidents.view')
                ? $this->dashboardService->recentServiceCases($filter)
                : collect(),
            'recentActivity' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivity()
                : collect(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $filter,
            'canManageTransactions' => $canManageTransactions,
        ]);
    }
}
