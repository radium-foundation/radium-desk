<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateOrderTransactionRequest;
use App\Http\Requests\UnlockOrderTransactionRequest;
use App\Http\Requests\UpdateOrderTransactionRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrderTransactionController extends Controller
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
        private readonly DashboardService $dashboardService,
    ) {}

    public function store(UpdateOrderTransactionRequest $request, Order $order): RedirectResponse|JsonResponse
    {
        try {
            $order = $this->orderTransactionService->assignTransactionId(
                order: $order,
                transactionId: $request->string('transaction_id')->trim()->toString(),
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
            $order->load('transactionAssigner');

            $incident = null;

            if ($request->filled('incident_id')) {
                $incident = Incident::query()
                    ->with(['order.transactionAssigner', 'creator', 'assignee'])
                    ->where('order_id', $order->id)
                    ->find($request->integer('incident_id'));
            }

            $user = $request->user();

            return response()->json([
                'message' => 'Transaction ID saved. Order marked as completed.',
                'order_id' => $order->id,
                'incident_id' => $incident?->id,
                'row_html' => $incident
                    ? view(
                        'dashboard.partials.service-case-row',
                        $this->dashboardService->serviceCaseRowViewData($incident, $user),
                    )->render()
                    : null,
                'kpi_strip_html' => $this->kpiStripHtmlFor($user),
            ]);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-transaction-assigned');
    }

    public function bulkStore(BulkUpdateOrderTransactionRequest $request): JsonResponse
    {
        $result = $this->orderTransactionService->assignTransactionIdToIncidents(
            incidentIds: $request->input('incident_ids'),
            transactionId: $request->string('transaction_id')->trim()->toString(),
            actor: $request->user(),
        );

        $count = $result['count'];
        $transactionId = $result['transaction_id'];
        $failedCount = count($result['failed_incidents']);
        $totalSelected = count($request->input('incident_ids'));

        if ($count > 0 && $failedCount > 0) {
            $message = "Transaction {$transactionId} applied to {$count} of {$totalSelected} selected service cases.";
        } else {
            $message = "Transaction {$transactionId} applied to {$count} service ".($count === 1 ? 'case' : 'cases').'.';
        }

        return response()->json([
            'message' => $message,
            'count' => $count,
            'transaction_id' => $transactionId,
            'rows' => $result['rows'],
            'failed_incidents' => $result['failed_incidents'],
            'kpi_strip_html' => $this->kpiStripHtmlFor($request->user()),
        ]);
    }

    private function kpiStripHtmlFor(User $user): string
    {
        return $this->dashboardService->renderKpiStrip(
            $this->dashboardService->statsFor($user),
            $user,
        );
    }

    public function destroy(UnlockOrderTransactionRequest $request, Order $order): RedirectResponse
    {
        $this->orderTransactionService->unlockTransaction(
            order: $order,
            actor: $request->user(),
            reason: $request->string('reason')->trim()->toString() ?: null,
        );

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-transaction-unlocked');
    }
}
