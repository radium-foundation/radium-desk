@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Statutory reports</h1>
        <p class="text-muted mb-0">Built from Desk statutory invoices only. Storefront databases are not queried. CGST/SGST/IGST stay blank until a place-of-supply rule is approved.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'reports'])

    @if($canGst)
        <h2 class="h5 mt-4">GST summary</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Rate %</th>
                        <th>Taxable</th>
                        <th>CGST</th>
                        <th>SGST</th>
                        <th>IGST</th>
                        <th>Unclassified tax</th>
                        <th>Tax total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($gstSummary as $row)
                        <tr>
                            <td>{{ $row['gst_percentage'] }}</td>
                            <td>{{ number_format($row['taxable_value'], 2) }}</td>
                            <td>{{ number_format($row['cgst'], 2) }}</td>
                            <td>{{ number_format($row['sgst'], 2) }}</td>
                            <td>{{ number_format($row['igst'], 2) }}</td>
                            <td>{{ number_format($row['unclassified_tax'], 2) }}</td>
                            <td>{{ number_format($row['tax_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No issued statutory invoices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($canSales)
        <h2 class="h5">Sales by channel</h2>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Invoice value</th>
                        <th>Tax</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($salesByChannel as $row)
                        <tr>
                            <td>{{ $row['channel'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['invoice_count'] }}</td>
                            <td>{{ number_format($row['invoice_value'], 2) }}</td>
                            <td>{{ number_format($row['tax_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No statutory invoices.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($canExport)
        <p class="mt-3"><a href="{{ route('finance.invoices.export') }}">Export invoice register CSV</a></p>
    @endif
@endsection
