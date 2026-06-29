@extends('layouts.app')

@section('title', 'Orders')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Orders</h1>
            <p class="text-muted mb-0">Manage order records and customer device information.</p>
        </div>
        @can('create', App\Models\Order::class)
            <a href="{{ route('orders.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Create Order
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Search Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('orders.index') }}" class="row g-3">
                <div class="col-md-6 col-lg-3">
                    <label for="filter_order_id" class="form-label">Order ID</label>
                    <input type="text" name="order_id" id="filter_order_id" class="form-control"
                           value="{{ $filters['order_id'] ?? '' }}" placeholder="Search order ID">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="filter_serial_number" class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" id="filter_serial_number" class="form-control"
                           value="{{ $filters['serial_number'] ?? '' }}" placeholder="Search serial number">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="filter_customer_name" class="form-label">Customer Name</label>
                    <input type="text" name="customer_name" id="filter_customer_name" class="form-control"
                           value="{{ $filters['customer_name'] ?? '' }}" placeholder="Search customer name">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="filter_transaction_id" class="form-label">Service Reference</label>
                    <input type="text" name="transaction_id" id="filter_transaction_id" class="form-control"
                           value="{{ $filters['transaction_id'] ?? '' }}" placeholder="Search service reference">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i> Search
                    </button>
                    <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($orders->isEmpty())
                <div class="p-4 text-center text-muted">
                    No orders found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Serial Number</th>
                                <th>Product</th>
                                <th>Customer</th>
                                <th>Status</th>
                                <th class="text-center">Incidents</th>
                                <th class="text-center">Refunds</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orders as $order)
                                <tr>
                                    <td class="fw-semibold">
                                        <a href="{{ route('orders.show', $order) }}" class="text-decoration-none">
                                            {{ $order->order_id }}
                                        </a>
                                    </td>
                                    <td>{{ $order->serial_number }}</td>
                                    <td>
                                        <div>{{ $order->product_name }}</div>
                                        <small class="text-muted">{{ $order->device_model }}</small>
                                    </td>
                                    <td>{{ $order->customer_name ?: '—' }}</td>
                                    <td>
                                        <span @class([
                                            'badge',
                                            'text-bg-success' => $order->status === \App\Enums\OrderStatus::Active,
                                            'text-bg-secondary' => $order->status === \App\Enums\OrderStatus::Closed,
                                        ])>
                                            {{ $order->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-center">{{ $order->incidents_count }}</td>
                                    <td class="text-center">{{ $order->refund_requests_count }}</td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-primary" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            @can('update', $order)
                                                <a href="{{ route('orders.edit', $order) }}" class="btn btn-outline-secondary" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($orders->hasPages())
            <div class="card-footer bg-white">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
@endsection
