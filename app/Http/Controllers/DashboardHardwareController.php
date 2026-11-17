<?php

namespace App\Http\Controllers;

use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use App\Services\HardwareFulfilment\HardwareStuckOrdersSnapshotRefresher;
use App\Services\HardwareFulfilment\HardwareStuckOrdersSnapshotStore;
use App\Services\Operations\OperationsRoleService;
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
            'last_page' => $payload['last_page'] ?? 1,
            'progressive' => (bool) ($payload['progressive'] ?? false),
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

    public function stuckSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $roles = app(OperationsRoleService::class);

        if (
            $user === null
            || ! $roles->usesAdminQueues($user)
            || ! $user->can('dashboard.hardware.view')
        ) {
            abort(403);
        }

        $store = app(HardwareStuckOrdersSnapshotStore::class);
        $refresher = app(HardwareStuckOrdersSnapshotRefresher::class);
        $presentation = $store->presentationForRender();
        $stale = true;
        $summary = null;
        $capturedAt = null;
        $version = null;

        if ($presentation !== null) {
            $summary = $presentation['summary'];
            $stale = $presentation['stale'];
            $capturedAt = $presentation['captured_at'];
            $version = $presentation['version'];
        }

        $refreshQueued = false;
        if ($stale || $summary === null) {
            $refreshQueued = true;
            if ($summary === null) {
                $result = $refresher->refreshAndBroadcast();
                if ($result['refreshed'] && is_array($result['summary'])) {
                    $summary = $result['summary'];
                    $stale = false;
                    $presentation = $store->presentationForRender();
                    $capturedAt = $presentation['captured_at'] ?? null;
                    $version = $presentation['version'] ?? null;
                    $refreshQueued = false;
                } else {
                    $presentation = $store->presentationForRender();
                    if ($presentation !== null) {
                        $summary = $presentation['summary'];
                        $stale = $presentation['stale'];
                        $capturedAt = $presentation['captured_at'];
                        $version = $presentation['version'];
                    } else {
                        return response()->json([
                            'message' => 'Stuck order counts are temporarily unavailable.',
                        ], 503);
                    }
                }
            } else {
                $refresher->queueBackgroundRefreshIfNeeded();
            }
        }

        $stuckMeta = [
            'captured_at' => $capturedAt,
            'version' => $version,
            'stale' => $stale,
        ];

        return response()->json([
            'html' => view('dashboard.partials.admin-hardware-stuck-card', [
                'summary' => $summary,
                'stuckMeta' => $stuckMeta,
            ])->render(),
            'summary' => $summary,
            'stale' => $stale,
            'captured_at' => $capturedAt,
            'version' => $version,
            'refresh_queued' => $refreshQueued,
        ]);
    }
}
