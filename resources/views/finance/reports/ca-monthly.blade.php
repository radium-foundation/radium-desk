@extends('layouts.app')

@section('title', 'CA Monthly Report')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance · Reports</p>
        <h1 class="h3 mb-1">CA Monthly Report</h1>
        <p class="text-muted mb-0">
            Statutory invoice line export for CA review. Period filter uses
            <strong>Date of Invoice</strong> (<code>statutory_invoices.issued_at</code>).
            CA confirmation is still required before treating this as the permanent accounting rule.
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'ca_monthly_report'])

    <form method="get" action="{{ route('finance.reports.ca-monthly.index') }}" class="row g-2 mb-3">
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="date_from">From Date</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control" required>
        </div>
        <div class="col-md-2">
            <label class="form-label small text-muted mb-1" for="date_to">To Date</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-outline-secondary w-100">Apply</button>
        </div>
    </form>

    <div class="alert alert-secondary">
        <div class="fw-semibold mb-2">Preflight</div>
        <ul class="mb-0 small">
            <li>Statutory invoices: {{ $preflight->invoiceCount }}</li>
            <li>Invoice lines: {{ $preflight->lineCount }}</li>
            <li>Cancelled invoices: {{ $preflight->cancelledInvoiceCount }}</li>
            <li>Lines missing SAC/HSN: {{ $preflight->missingHsnSacLineCount }}</li>
            <li>Invoices missing buyer GSTIN: {{ $preflight->missingBuyerGstinInvoiceCount }}</li>
            <li>Invoices missing IRN: {{ $preflight->missingIrnInvoiceCount }}</li>
            <li>Invoices missing STATE: {{ $preflight->missingStateInvoiceCount }}</li>
        </ul>
    </div>

    @foreach ($preflight->warnings() as $warning)
        <div class="alert alert-warning py-2 small mb-2">{{ $warning }}</div>
    @endforeach

    @if ($canExport)
        <p class="mb-3">
            <a href="{{ route('finance.reports.ca-monthly.export.xlsx', request()->query()) }}" class="btn btn-primary">Export XLSX</a>
            <a href="{{ route('finance.reports.ca-monthly.export.csv', request()->query()) }}" class="btn btn-outline-primary">Export CSV</a>
        </p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    @foreach ($headers as $header)
                        <th class="text-nowrap small">{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        @foreach ($previewRows[$line->id] ?? [] as $value)
                            <td class="small text-nowrap">{{ $value !== '' ? $value : '—' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($headers) }}" class="text-muted">No statutory invoice lines for the selected date range.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $lines->links() }}
@endsection
