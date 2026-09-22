@extends('layouts.app')

@section('title', 'Service sales')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Service POS</p>
            <h1 class="h3 mb-1">Service sales</h1>
            <p class="text-muted mb-0">Proformas converted to service orders and statutory invoices.</p>
        </div>
        <a href="{{ route('service-pos.counter.create') }}" class="btn btn-primary">New proforma</a>
    </div>
    @include('commerce.partials.workspace-nav', ['active' => 'service_sales'])
    @include('inventory.partials.branch-scope-empty')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="text" name="q" class="form-control" placeholder="Order, proforma, invoice, phone, name" value="{{ $filters['q'] ?? '' }}">
        </div>
        <div class="col-md-3">
            <select name="branch_id" class="form-select">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach(\App\Enums\ServiceOrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Proforma</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Branch</th>
                        <th>Total</th>
                        <th>Order status</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td><a href="{{ route('service-pos.orders.show', $order) }}">{{ $order->order_number }}</a></td>
                            <td>
                                @if($order->quote)
                                    <a href="{{ route('service-pos.quotes.show', $order->quote) }}">{{ $order->quote->quote_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if($order->statutoryInvoice)
                                    <a href="{{ route('finance.invoices.show', $order->statutoryInvoice) }}">{{ $order->statutoryInvoice->invoice_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $order->buyer_name }} · {{ $order->buyer_phone }}</td>
                            <td>{{ $order->branch?->code }}</td>
                            <td>{{ number_format((float) $order->total, 2) }}</td>
                            <td>{{ $order->status->label() }}</td>
                            <td>{{ $order->payment_status->label() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted p-4">No service sales yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $orders->links() }}</div>
@endsection
