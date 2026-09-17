@extends('layouts.app')

@section('title', $quote->quote_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Internal proforma</p>
            <h1 class="h3 mb-1">{{ $quote->quote_number }}</h1>
            <p class="text-muted mb-0">This is a quote/proforma only — <strong>not</strong> a statutory GST invoice.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('service-pos.quotes.print', $quote) }}" class="btn btn-outline-secondary" target="_blank">Print</a>
            @if($canConvert)
                <form method="POST" action="{{ route('service-pos.quotes.convert', $quote) }}">@csrf
                    <button class="btn btn-primary">Convert to service order</button>
                </form>
            @endif
        </div>
    </div>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6"><strong>Customer:</strong> {{ $quote->buyer_name }} ({{ $quote->buyer_phone }})</div>
                <div class="col-md-6"><strong>Branch:</strong> {{ $quote->branch?->code }}</div>
                <div class="col-md-6"><strong>Billing state:</strong> {{ $quote->billing_state }}</div>
                <div class="col-md-6"><strong>Status:</strong> {{ $quote->status->label() }}</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>#</th><th>Description</th><th>SAC</th><th>GST</th><th class="text-end">Qty</th><th class="text-end">Ex-GST</th><th class="text-end">Tax</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                    @foreach($quote->lines as $line)
                        <tr>
                            <td>{{ $line->line_no }}</td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->sac_code }}</td>
                            <td>{{ $line->gst_rate }}%</td>
                            <td class="text-end">{{ $line->qty }}</td>
                            <td class="text-end">₹{{ number_format((float) $line->unit_price_ex_gst, 2) }}</td>
                            <td class="text-end">₹{{ number_format((float) $line->tax_total, 2) }}</td>
                            <td class="text-end">₹{{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><th colspan="7" class="text-end">Total</th><th class="text-end">₹{{ number_format((float) $quote->total, 2) }}</th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if($quote->convertedServiceOrder)
        <div class="alert alert-info mt-3">Converted to <a href="{{ route('service-pos.orders.show', $quote->convertedServiceOrder) }}">{{ $quote->convertedServiceOrder->order_number }}</a></div>
    @endif
@endsection
