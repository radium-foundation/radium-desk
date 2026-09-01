@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Statutory invoices</h1>
        <p class="text-muted mb-0">GST tax invoices issued by Desk. POS INV-* receipts are not listed here.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @unless($numberingConfigured)
        <div class="alert alert-warning">
            Legal invoice series is unset. Statutory minting is disabled until the CA approves the series format.
        </div>
    @endunless

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-4">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Number, customer, GSTIN, source">
        </div>
        <div class="col-md-2">
            <input type="text" name="channel" value="{{ $filters['channel'] ?? '' }}" class="form-control" placeholder="Channel">
        </div>
        <div class="col-md-2">
            <input type="text" name="status" value="{{ $filters['status'] ?? '' }}" class="form-control" placeholder="Status">
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-outline-secondary">Filter</button>
            @if($canExport)
                <a href="{{ route('finance.invoices.export', request()->query()) }}" class="btn btn-outline-primary">Export CSV</a>
            @endif
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Channel</th>
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
                        <td>{{ $invoice->buyer_name ?: '—' }}</td>
                        <td>{{ $invoice->buyer_gstin ?: '—' }}</td>
                        <td>{{ number_format((float) $invoice->taxable_value, 2) }}</td>
                        <td>{{ number_format((float) $invoice->tax_total, 2) }}</td>
                        <td>{{ number_format((float) $invoice->invoice_value, 2) }}</td>
                        <td>{{ $invoice->status->label() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-muted">No statutory invoices. Historical Admin invoices are not imported.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $invoices->links() }}
@endsection
