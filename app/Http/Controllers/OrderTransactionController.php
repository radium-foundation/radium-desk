<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnlockOrderTransactionRequest;
use App\Http\Requests\UpdateOrderTransactionRequest;
use App\Models\Order;
use App\Services\OrderTransactionService;
use Illuminate\Http\RedirectResponse;

class OrderTransactionController extends Controller
{
    public function __construct(
        private readonly OrderTransactionService $orderTransactionService,
    ) {}

    public function store(UpdateOrderTransactionRequest $request, Order $order): RedirectResponse
    {
        $this->orderTransactionService->assignTransactionId(
            order: $order,
            transactionId: $request->string('transaction_id')->trim()->toString(),
            actor: $request->user(),
        );

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-transaction-assigned');
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
