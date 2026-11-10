<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use App\Services\Shipping\ShiprocketWalletBalanceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardHardwareController extends Controller
{
    public function __construct(
        private readonly HardwareDashboardWorkspace $hardwareDashboard,
        private readonly DashboardPersonalizationService $dashboardPersonalization,
        private readonly ShiprocketWalletBalanceReadService $walletBalance,
    ) {}

    public function rows(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('incidents.view') || ! $this->dashboardPersonalization->canViewHardwareOrders($user)) {
            abort(403);
        }

        $payload = $this->hardwareDashboard->rowsPayload($request);

        $tbodyHtml = view('dashboard.partials.hardware-workspace-tbody', [
            'rows' => $payload['rows'],
            'incidentIds' => $payload['incidentIds'],
            'operableFulfilmentIds' => $payload['operableFulfilmentIds'],
            'canOperateHardware' => $payload['canOperateHardware'],
            'isShippedScope' => ($payload['scope'] ?? '') === 'shipped',
            'search' => $payload['search'] ?? '',
        ])->render();

        $paginationHtml = view('dashboard.partials.hardware-workspace-pagination', [
            'hardwareWorkspace' => $payload,
        ])->render();

        return response()->json([
            'rows_html' => $tbodyHtml,
            'pagination_html' => $paginationHtml,
            'scope' => $payload['scope'],
            'filter' => $payload['filter'],
            'search' => $payload['search'],
            'page' => $payload['page'],
            'per_page' => $payload['per_page'],
            'unfiltered_total' => $payload['unfilteredTotal'],
            'inspected_fulfilment_count' => $payload['inspected_fulfilment_count'],
            'scope_counts' => $payload['scope_counts'],
            'filter_counts' => $payload['filter_counts'],
            'operable_fulfilment_ids' => $payload['operableFulfilmentIds'],
            'can_operate_hardware' => $payload['canOperateHardware'],
        ]);
    }

    public function shiprocketBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $user->can('incidents.view') || ! $this->dashboardPersonalization->canViewHardwareOrders($user)) {
            abort(403);
        }

        $balance = $this->walletBalance->presentFor($user);
        $html = view('dashboard.partials.hardware-shiprocket-balance', [
            'hardwareWorkspace' => ['shiprocketBalance' => $balance],
        ])->render();

        return response()->json([
            'html' => $html,
        ]);
    }
}
