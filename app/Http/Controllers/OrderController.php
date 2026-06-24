<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Order;
use App\Services\RemarkTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function __construct(
        private readonly RemarkTimelineService $remarkTimelineService,
    ) {
        $this->authorizeResource(Order::class, 'order');
    }

    public function index(Request $request): View
    {
        $orders = Order::query()
            ->withCount(['incidents', 'refundRequests'])
            ->when($request->filled('order_id'), function ($query) use ($request) {
                $query->where('order_id', 'like', '%'.$request->string('order_id')->trim().'%');
            })
            ->when($request->filled('serial_number'), function ($query) use ($request) {
                $query->where('serial_number', 'like', '%'.$request->string('serial_number')->trim().'%');
            })
            ->when($request->filled('customer_name'), function ($query) use ($request) {
                $query->where('customer_name', 'like', '%'.$request->string('customer_name')->trim().'%');
            })
            ->when($request->filled('transaction_id'), function ($query) use ($request) {
                $query->where('transaction_id', 'like', '%'.$request->string('transaction_id')->trim().'%');
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'filters' => $request->only(['order_id', 'serial_number', 'customer_name', 'transaction_id']),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $term = $request->string('q')->trim()->toString();

        if ($term === '') {
            return response()->json([]);
        }

        $orders = Order::query()
            ->where(function ($query) use ($term) {
                $query->where('order_id', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', "%{$term}%");
            })
            ->orderBy('order_id')
            ->limit(10)
            ->get(['id', 'order_id', 'serial_number', 'product_name', 'device_model']);

        return response()->json($orders);
    }

    public function create(): View
    {
        return view('orders.create', [
            'order' => new Order([
                'status' => OrderStatus::Active,
            ]),
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $order = Order::query()->create([
            ...$request->validated(),
            'status' => OrderStatus::Active,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-created');
    }

    public function show(Order $order): View
    {
        $order->loadCount(['incidents', 'refundRequests']);

        return view('orders.show', [
            'order' => $order,
            'timelineRemarks' => $this->remarkTimelineService->forOrder($order),
        ]);
    }

    public function edit(Order $order): View
    {
        return view('orders.edit', [
            'order' => $order,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        $order->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'order-updated');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $order->delete();

        return redirect()
            ->route('orders.index')
            ->with('status', 'order-deleted');
    }
}
