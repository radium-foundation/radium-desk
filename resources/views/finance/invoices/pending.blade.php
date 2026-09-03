@extends('layouts.app')

@section('title', 'Pending statutory invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Pending statutory invoices</h1>
        <p class="text-muted mb-0">Manual issue only. Automatic issuance stays off. September 1, 2026 onward.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Channel</th>
                    <th>Source</th>
                    <th>Payment</th>
                    <th>Eligible</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $order)
                    <tr>
                        <td>{{ $order->order_no }}</td>
                        <td>{{ $order->channel->value }}</td>
                        <td>{{ $order->source_id }}</td>
                        <td>{{ $order->payment_status }}</td>
                        <td>{{ $order->invoice_eligible ? 'Yes' : 'No' }}</td>
                        <td>
                            <a href="{{ route('finance.invoices.commerce-orders.show', $order) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-muted">No pending channel orders.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
