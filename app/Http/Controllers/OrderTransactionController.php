<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateOrderTransactionRequest;
use App\Http\Requests\UnlockOrderTransactionRequest;
use App\Http\Requests\UpdateOrderTransactionRequest;
use App\Models\Incident;
use App\Models\Order;
use App\Services\OrderTransactionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class OrderTransactionController extends Controller
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
    ) {}

    public function store(UpdateOrderTransactionRequest $request, Order $order): RedirectResponse|JsonResponse
    {
        $order = $this->orderTransactionService->assignTransactionId(
            order: $order,
            transactionId: $request->string('transaction_id')->trim()->toString(),
            actor: $request->user(),
        );

        if ($request->wantsJson()) {
            $order->load('transactionAssigner');

            $incident = null;

            if ($request->filled('incident_id')) {
                $incident = Incident::query()
                    ->with(['order.transactionAssigner', 'creator'])
                    ->where('order_id', $order->id)
                    ->find($request->integer('incident_id'));
            }

            $canManageTransactions = $request->user()?->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]) ?? false;

            return response()->json([
                'message' => 'Transaction ID saved. Order marked as completed.',
                'order_id' => $order->id,
                'incident_id' => $incident?->id,
                'row_html' => $incident
                    ? view('dashboard.partials.service-case-row', [
                        'serviceCase' => $incident,
                        'canManageTransactions' => $canManageTransactions,
                        'canSelectRows' => $canManageTransactions,
                    ])->render()
                    : null,
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

        return response()->json([
            'message' => "Transaction {$transactionId} applied to {$count} service ".($count === 1 ? 'case' : 'cases').'.',
            'count' => $count,
            'transaction_id' => $transactionId,
            'rows' => $result['rows'],
        ]);
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
