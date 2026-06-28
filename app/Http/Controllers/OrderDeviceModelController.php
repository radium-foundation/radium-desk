<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignOrderDeviceModelRequest;
use App\Models\DeviceModel;
use App\Models\Incident;
use App\Models\Order;
use App\Services\DashboardService;
use App\Services\OrderDeviceModelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class OrderDeviceModelController extends Controller
{
    public function __construct(
        private readonly OrderDeviceModelService $orderDeviceModelService,
        private readonly DashboardService $dashboardService,
    ) {}

    public function store(AssignOrderDeviceModelRequest $request, Order $order): JsonResponse
    {
        $deviceModel = DeviceModel::query()->findOrFail($request->integer('device_model_id'));

        try {
            $order = $this->orderDeviceModelService->assignDeviceModel(
                order: $order,
                deviceModel: $deviceModel,
                actor: $request->user(),
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        }

        $order->load(['deviceModel', 'deviceModelAssigner']);

        $incident = null;

        if ($request->filled('incident_id')) {
            $incident = Incident::query()
                ->with(['order.deviceModel', 'order.deviceModelAssigner', 'order.transactionAssigner', 'creator', 'assignee'])
                ->where('order_id', $order->id)
                ->find($request->integer('incident_id'));
        }

        $user = $request->user();

        return response()->json([
            'message' => 'Device model assigned successfully.',
            'order_id' => $order->id,
            'incident_id' => $incident?->id,
            'row_html' => $incident
                ? view(
                    'dashboard.partials.service-case-row',
                    $this->dashboardService->serviceCaseRowViewData($incident, $user),
                )->render()
                : null,
        ]);
    }
}
