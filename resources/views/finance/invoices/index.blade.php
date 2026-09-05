@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Statutory invoices</h1>
        <p class="text-muted mb-0">GST tax invoices issued manually from Finance Hub. Automatic issuance is OFF. POS INV-* receipts are not listed here. Historical Admin INV* numbers are reprinted from <a href="{{ route('finance.invoices.historical') }}">Historical reprint</a>.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @unless($numberingConfigured)
        <div class="alert alert-warning">
            Legal invoice series is unset. Statutory minting is disabled until the CA approves the series format.
        </div>
    @endunless

    @include('finance.partials.report-filters', [
        'action' => route('finance.invoices.index'),
        'filters' => $filters,
    ])

    @if($canExport)
        <p class="mb-3">
            <a href="{{ route('finance.invoices.export', request()->query()) }}" class="btn btn-outline-primary">Export CSV</a>
        </p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Channel</th>
                    <th>Source</th>
                    <th>Customer</th>
                    <th>GSTIN</th>
                    <th>Taxable</th>
                    <th>Tax</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoices as $invoice)
                    <tr>
                        <td><a href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                        <td>{{ $invoice->issued_at?->timezone(config('app.timezone'))->format('d M Y') }}</td>
                        <td>{{ $invoice->channel->label() }}</td>
                        <td class="small">{{ $invoice->source_id }}</td>
                        <td>{{ $invoice->buyer_name ?: '—' }}</td>
                        <td>{{ $invoice->buyer_gstin ?: '—' }}</td>
                        <td>{{ number_format((float) $invoice->taxable_value, 2) }}</td>
                        <td>{{ number_format((float) $invoice->tax_total, 2) }}</td>
                        <td>{{ number_format((float) $invoice->invoice_value, 2) }}</td>
                        <td>{{ $invoice->status->label() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-muted">No statutory invoices. Historical Admin invoices are not imported. POS INV-* receipts are excluded.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $invoices->links() }}
@endsection
