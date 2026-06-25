<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardLiveController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $filter = $request->string('filter')->toString() ?: 'pending_admin';

        if (! in_array($filter, ['all', 'pending_admin', 'completed', 'high_priority', 'overdue', 'warning'], true)) {
            $filter = 'pending_admin';
        }

        $stats = $this->dashboardService->statsFor($user);
        $canManageTransactions = $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $recentServiceCases = $user->can('incidents.view')
            ? $this->dashboardService->recentServiceCases($filter)
            : collect();

        $rows = $recentServiceCases->map(function ($serviceCase) use ($canManageTransactions) {
            return [
                'incident_id' => $serviceCase->id,
                'html' => view('dashboard.partials.service-case-row', [
                    'serviceCase' => $serviceCase,
                    'canManageTransactions' => $canManageTransactions,
                    'canSelectRows' => $canManageTransactions,
                ])->render(),
            ];
        })->values();

        return response()->json([
            'action_stats_html' => view('dashboard.partials.action-stats', compact('stats'))->render(),
            'sla_cards_html' => view('dashboard.partials.sla-alert-cards', compact('stats'))->render(),
            'service_cases_empty' => $recentServiceCases->isEmpty(),
            'service_cases_empty_html' => view('dashboard.partials.service-cases-empty')->render(),
            'rows' => $rows,
            'incident_ids' => $recentServiceCases->pluck('id')->values(),
        ]);
    }
}
