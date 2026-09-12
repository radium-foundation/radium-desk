@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">Purchase Orders</h1>
        </div>
        @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_CREATE)
            <a href="{{ route('purchasing.purchase-orders.create') }}" class="btn btn-primary">New PO</a>
        @endcan
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3"><input type="text" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="PO number"></div>
        <div class="col-md-3">
            <select name="vendor_id" class="form-select">
                <option value="">All vendors</option>
                @foreach($vendors as $vendor)
                    <option value="{{ $vendor->id }}" @selected((string)($filters['vendor_id'] ?? '') === (string)$vendor->id)>{{ $vendor->business_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach(\App\Enums\PurchaseOrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>PO</th>
                        <th>Vendor</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td>{{ $order->po_number }}</td>
                            <td>{{ $order->vendor->business_name }}</td>
                            <td>{{ $order->po_date->format('Y-m-d') }}</td>
                            <td>{{ $order->status->label() }}</td>
                            <td class="text-end">₹{{ number_format((float) $order->grand_total, 2) }}</td>
                            <td><a href="{{ route('purchasing.purchase-orders.show', $order) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted p-4">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $orders->links() }}</div>
@endsection
