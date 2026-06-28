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

        if (! in_array($filter, ['all', 'pending_admin', 'completed', 'high_priority', 'overdue', 'warning'], true)) {
            $filter = 'pending_admin';
        }

        $canManageTransactions = $user->hasAnyRole([
            \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
            \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $reopenQuickCreate = (bool) session('reopen_quick_create', false);

        return view('dashboard.index', [
            'stats' => $this->dashboardService->statsFor($user),
            'reopenQuickCreate' => $reopenQuickCreate,
            'recentServiceCases' => $user->can('incidents.view')
                ? $this->dashboardService->recentServiceCases(
                    $filter,
                    $this->dashboardService->serviceCaseLimitForFilter($filter),
                )
                : collect(),
            'recentActivity' => $user->can('audit-logs.view')
                ? $this->dashboardService->recentActivity()
                : collect(),
            'canQuickCreate' => $user->can('orders.view') && $user->can('incidents.create'),
            'serviceCaseFilter' => $filter,
            'canManageTransactions' => $canManageTransactions,
            'canAssignDeviceModel' => $user->can('incidents.update'),
            'canReassignServiceCases' => $user->can('incidents.update'),
            'canCreateRemarks' => $user->can('create', \App\Models\Remark::class),
            'canShowServiceCaseActions' => $user->hasAnyRole([
                \Database\Seeders\RolePermissionSeeder::ROLE_ADMIN,
                \Database\Seeders\RolePermissionSeeder::ROLE_SUPERADMIN,
            ]) || $user->can('create', \App\Models\Remark::class) || $user->can('incidents.update'),
            'enabledProducts' => app(\App\Services\SettingService::class)->enabledProductNames(),
            'enabledSources' => app(\App\Services\SettingService::class)->enabledSources(),
            'dashboardLiveMode' => config('dashboard.live_mode', 'auto'),
            'dashboardPollIntervalMs' => config('dashboard.poll_interval_ms', 30000),
            'reverbConfigured' => config('broadcasting.default') === 'reverb'
                && config('broadcasting.connections.reverb.key'),
        ]);
    }
}
