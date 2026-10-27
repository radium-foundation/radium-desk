<?php

namespace App\Http\Controllers;

use App\Http\Requests\CustomerOrderHistoryRequest;
use App\Services\CustomerOrderHistory\CustomerOrderHistoryService;
use Illuminate\Http\JsonResponse;

class CustomerOrderHistoryController extends Controller
{
    public function __construct(
        private readonly CustomerOrderHistoryService $customerOrderHistory,
    ) {}

    public function show(CustomerOrderHistoryRequest $request): JsonResponse
    {
        $payload = $this->customerOrderHistory->historyForContext(
            phone: $request->string('phone')->trim()->toString() ?: null,
            email: $request->string('email')->trim()->toString() ?: null,
            businessOrderId: $request->string('order_id')->trim()->toString() ?: null,
            serialNumber: $request->string('serial_number')->trim()->toString() ?: null,
            highlightOrderDbId: $request->integer('highlight_order_id') ?: null,
        );

        return response()->json([
            'order_history' => $payload,
        ]);
    }
}
