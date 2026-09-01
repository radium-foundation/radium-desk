@extends('layouts.app')

@section('title', 'Invoice '.$invoice->invoice_number)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">{{ $invoice->invoice_number }}</h1>
        <p class="text-muted mb-0">
            {{ $invoice->document_type->label() }} · {{ $invoice->status->label() }} ·
            {{ $invoice->channel->label() }} · source {{ $invoice->source_type }} {{ $invoice->source_id }}
            @if($invoice->source_order_id)
                · channel order {{ $invoice->source_order_id }}
            @endif
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @if($invoice->status->value === 'cancelled')
        <div class="alert alert-warning">
            Cancelled on {{ $invoice->cancelled_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}.
            The invoice number is kept and is not reused.
            @if($invoice->cancel_reason)
                Reason: {{ $invoice->cancel_reason }}
            @endif
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Buyer</h2>
                    <div>{{ $invoice->buyer_name ?: '—' }}</div>
                    <div>{{ $invoice->buyer_phone ?: '—' }}</div>
                    <div>GSTIN {{ $invoice->buyer_gstin ?: '—' }}</div>
                    <div class="small text-muted mt-2">Place of supply {{ $invoice->place_of_supply_state ?: 'unset' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h6 text-muted">Seller / payment</h2>
                    <div>{{ $invoice->seller_name ?: '—' }}</div>
                    <div>GSTIN {{ $invoice->seller_gstin ?: '—' }}</div>
                    <div>{{ $invoice->payment_method ?: '—' }} {{ $invoice->payment_reference }}</div>
                    @if($invoice->inventorySale?->invoice_number)
                        <div class="small text-muted mt-2">POS internal receipt {{ $invoice->inventorySale->invoice_number }} (not a GST number)</div>
                    @endif
                    <div class="small text-muted mt-2">CGST/SGST/IGST split is stored only when provided. Unclassified tax is not fabricated. GST credit notes are not issued yet.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>HSN/SAC</th>
                    <th>Qty</th>
                    <th>Taxable</th>
                    <th>Tax</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->hsn_sac ?: '—' }}</td>
                        <td>{{ $line->qty }}</td>
                        <td>{{ number_format((float) $line->taxable_value, 2) }}</td>
                        <td>{{ number_format((float) $line->tax_total, 2) }}</td>
                        <td>{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
