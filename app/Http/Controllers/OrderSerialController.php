<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrderSerialRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Services\DashboardService;
use App\Services\OrderSerialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrderSerialController extends Controller
{
    public function __construct(
        private readonly OrderSerialService $orderSerialService,
        private readonly DashboardService $dashboardService,
    ) {}

    public function store(UpdateOrderSerialRequest $request, Order $order): RedirectResponse|JsonResponse
    {
        try {
            $order = $this->orderSerialService->assignSerialNumber(
                order: $order,
                serialNumber: $request->string('serial_number')->toString(),
                actor: $request->user(),
            );
        } catch (ValidationException $exception) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 422);
            }

            throw $exception;
        }

        if ($request->wantsJson()) {
            $order->load('serialEnterer');

            $incident = null;

            if ($request->filled('incident_id')) {
                $incident = Incident::query()
                    ->with(['order.serialEnterer', 'order.transactionAssigner', 'creator', 'assignee'])
                    ->where('order_id', $order->id)
                    ->find($request->integer('incident_id'));
            }

            $user = $request->user();

            return response()->json([
                'message' => 'Serial Number saved and locked successfully.',
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

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-serial-assigned');
    }
}
