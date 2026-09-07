@extends('layouts.app')

@section('title', 'Invoice '.$invoice->invoice_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
            <h1 class="h3 mb-1">{{ $invoice->invoice_number }}</h1>
            <p class="text-muted mb-0">
                {{ $invoice->document_type->label() }} · {{ $invoice->status->label() }} ·
                {{ $invoice->channel->label() }} · source {{ $invoice->source_type }} {{ $invoice->source_id }}
            </p>
        </div>
        <a href="{{ route('finance.invoices.pdf', $invoice) }}" class="btn btn-outline-secondary">GST PDF</a>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($invoice->status->value === 'cancelled')
        <div class="alert alert-warning">
            Cancelled on {{ $invoice->cancelled_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}.
            The invoice number is kept and is not reused.
            @if($invoice->cancel_reason)
                Reason: {{ $invoice->cancel_reason }}
            @endif
        </div>
    @endif

    <p>Buyer: {{ $invoice->buyer_name ?: '—' }} · GSTIN {{ $invoice->buyer_gstin ?: 'B2C' }}</p>
    <p>Seller: {{ $invoice->seller_name ?: '—' }} · GSTIN {{ $invoice->seller_gstin ?: '—' }}</p>
    <p>Place of supply {{ $invoice->place_of_supply_state ?: 'unset' }}</p>
    @if($invoice->inventorySale?->invoice_number)
        <p class="text-muted">POS internal receipt {{ $invoice->inventorySale->invoice_number }} (not a GST number)</p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>HSN/SAC</th>
                    <th>Qty</th>
                    <th>Taxable</th>
                    <th>GST %</th>
                    <th>CGST</th>
                    <th>SGST</th>
                    <th>IGST</th>
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
                        <td>{{ $line->gst_percentage !== null ? number_format((float) $line->gst_percentage, 2) : '—' }}</td>
                        <td>{{ $line->cgst !== null ? number_format((float) $line->cgst, 2) : '—' }}</td>
                        <td>{{ $line->sgst !== null ? number_format((float) $line->sgst, 2) : '—' }}</td>
                        <td>{{ $line->igst !== null ? number_format((float) $line->igst, 2) : '—' }}</td>
                        <td>{{ number_format((float) $line->tax_total, 2) }}</td>
                        <td>{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p>
        Taxable {{ number_format((float) $invoice->taxable_value, 2) }}
        · Total GST {{ number_format((float) $invoice->tax_total, 2) }}
        · CGST {{ $invoice->cgst !== null ? number_format((float) $invoice->cgst, 2) : '—' }}
        · SGST {{ $invoice->sgst !== null ? number_format((float) $invoice->sgst, 2) : '—' }}
        · IGST {{ $invoice->igst !== null ? number_format((float) $invoice->igst, 2) : '—' }}
        · Invoice value {{ number_format((float) $invoice->invoice_value, 2) }}
    </p>
@endsection
