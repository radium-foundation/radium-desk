@extends('layouts.app')

@section('title', $sale->sale_no)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
            <h1 class="h3 mb-1">{{ $sale->sale_no }}</h1>
            <p class="text-muted mb-0">
                Invoice {{ $sale->invoice_number }} · {{ $sale->status->label() }} ·
                Finance {{ $sale->finance_handoff_status->label() }}
            </p>
        </div>
        <a href="{{ route('pos.sales.invoice', $sale) }}" class="btn btn-outline-secondary">Invoice</a>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'sales'])

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Customer</h2>
                    <div>{{ $sale->customer?->name }}</div>
                    <div>{{ $sale->customer?->phone }}</div>
                    <div>{{ $sale->customer?->email ?: '—' }}</div>
                    <div class="mt-2 small text-muted">{{ $sale->branch?->name }} · {{ $sale->payment_method }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Totals</h2>
                    <div>Subtotal {{ number_format((float) $sale->subtotal, 2) }}</div>
                    <div>Discount {{ number_format((float) $sale->discount, 2) }}</div>
                    <div>Tax {{ number_format((float) $sale->tax, 2) }}</div>
                    <div class="fw-semibold">Total {{ number_format((float) $sale->total, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Tax</th>
                        <th>Total</th>
                        <th>Serials</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sale->lines as $line)
                        <tr>
                            <td>{{ $line->product?->sku }} — {{ $line->product?->name }}</td>
                            <td>{{ $line->qty }}</td>
                            <td>{{ number_format((float) $line->unit_price, 2) }}</td>
                            <td>{{ number_format((float) $line->tax, 2) }}</td>
                            <td>{{ number_format((float) $line->line_total, 2) }}</td>
                            <td>
                                @foreach($line->serials as $assignment)
                                    <div><a href="{{ route('inventory.serials.show', $assignment->serial) }}">{{ $assignment->serial?->serial_number }}</a></div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($canCancel && $sale->status === \App\Enums\InventorySaleStatus::Completed)
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6">Cancel / return foundation</h2>
                <p class="text-muted small">Restores serials and quantity to the selling branch. Does not reverse the finance journal in this gate.</p>
                <form method="POST" action="{{ route('pos.sales.cancel', $sale) }}" class="d-flex flex-wrap gap-2 mb-2">
                    @csrf
                    <input type="text" name="reason" class="form-control" style="max-width: 24rem;" required placeholder="Cancel reason">
                    <button class="btn btn-outline-danger">Cancel sale</button>
                </form>
                <form method="POST" action="{{ route('pos.sales.return', $sale) }}" class="d-flex flex-wrap gap-2">
                    @csrf
                    <input type="text" name="reason" class="form-control" style="max-width: 24rem;" required placeholder="Return reason">
                    <button class="btn btn-outline-secondary">Return sale</button>
                </form>
            </div>
        </div>
    @endif
@endsection
