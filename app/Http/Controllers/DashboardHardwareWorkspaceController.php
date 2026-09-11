<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardHardwareWorkspaceController extends Controller
{
    public function __construct(
        private readonly HardwareDashboardWorkspace $hardwareDashboard,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
    ) {}

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $this->dashboardPersonalization->canViewHardwareOrders($user), 403);

        $hardwareWorkspace = $this->hardwareDashboard->present($request);

        return response()->json([
            'ok' => true,
            'workspace_html' => view('dashboard.partials.hardware-workspace', [
                'hardwareWorkspace' => $hardwareWorkspace,
            ])->render(),
            'counts' => $hardwareWorkspace['counts'],
            'hardware_chip_count' => $this->hardwareDashboard->chipCount(),
        ]);
    }
}
