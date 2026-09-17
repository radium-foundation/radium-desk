@extends('layouts.app')

@section('title', $order->order_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Service order</p>
            <h1 class="h3 mb-1">{{ $order->order_number }}</h1>
            @if($order->quote)<div class="small text-muted">From proforma <a href="{{ route('service-pos.quotes.show', $order->quote) }}">{{ $order->quote->quote_number }}</a></div>@endif
        </div>
        <div class="d-flex gap-2">
            @if($canIssueInvoice)
                <form method="POST" action="{{ route('finance.invoices.service-orders.issue', $order) }}">@csrf
                    <button class="btn btn-primary">Issue statutory invoice</button>
                </form>
            @endif
            @if($order->statutoryInvoice)
                <a href="{{ route('finance.invoices.show', $order->statutoryInvoice) }}" class="btn btn-outline-primary">View invoice {{ $order->statutoryInvoice->invoice_number }}</a>
            @endif
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body row g-2">
            <div class="col-md-4"><strong>Customer</strong><br>{{ $order->buyer_name }}</div>
            <div class="col-md-4"><strong>Payment</strong><br>{{ $order->payment_status->label() }}</div>
            <div class="col-md-4"><strong>Outstanding</strong><br>@if($outstanding !== null) ₹{{ number_format($outstanding, 2) }}@else — @endif</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Description</th><th>SAC</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach($order->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->sac_code }}</td>
                            <td class="text-end">{{ $line->qty }}</td>
                            <td class="text-end">₹{{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><th colspan="3" class="text-end">Order total</th><th class="text-end">₹{{ number_format((float) $order->total, 2) }}</th></tr></tfoot>
            </table>
        </div>
    </div>
@endsection
