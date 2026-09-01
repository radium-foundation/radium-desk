@extends('layouts.app')

@section('title', 'Reports')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Month-end reports</h1>
        <p class="text-muted mb-0">
            Desk statutory invoices and accepted channel orders only. Storefront databases are not queried.
            CGST/SGST/IGST stay blank unless the source already supplied the split. POS INV-* is not a GST number.
        </p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'reports'])

    @include('finance.partials.report-filters', [
        'action' => route('finance.reports.index'),
        'filters' => $filters,
    ])

    @if($summary !== [])
        <h2 class="h5 mt-2">Period summary</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <tbody>
                    @if($canInvoices || $canGst || $canSales)
                        <tr><th>Issued invoices</th><td>{{ $summary['issued_count'] ?? 0 }}</td></tr>
                        <tr><th>Issued taxable</th><td>{{ number_format((float) ($summary['issued_taxable_value'] ?? 0), 2) }}</td></tr>
                        <tr><th>Issued tax</th><td>{{ number_format((float) ($summary['issued_tax_total'] ?? 0), 2) }}</td></tr>
                        <tr><th>Issued invoice value</th><td>{{ number_format((float) ($summary['issued_invoice_value'] ?? 0), 2) }}</td></tr>
                        <tr><th>Cancelled invoices (number kept)</th><td>{{ $summary['cancelled_count'] ?? 0 }}</td></tr>
                        <tr><th>Unclassified tax (no CGST/SGST/IGST split)</th><td>{{ number_format((float) ($summary['unclassified_tax_total'] ?? 0), 2) }}</td></tr>
                    @endif
                    @if($canSales)
                        <tr><th>Channel orders received</th><td>{{ $summary['channel_orders_received'] ?? 0 }}</td></tr>
                        <tr><th>Eligible, not invoiced</th><td>{{ $summary['channel_orders_eligible_uninvoiced'] ?? 0 }}</td></tr>
                        <tr><th>Ineligible for invoice</th><td>{{ $summary['channel_orders_ineligible'] ?? 0 }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    @endif

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
                            <td>{{ $row['has_classified_split'] ? number_format($row['cgst'], 2) : '—' }}</td>
                            <td>{{ $row['has_classified_split'] ? number_format($row['sgst'], 2) : '—' }}</td>
                            <td>{{ $row['has_classified_split'] ? number_format($row['igst'], 2) : '—' }}</td>
                            <td>{{ number_format($row['unclassified_tax'], 2) }}</td>
                            <td>{{ number_format($row['tax_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No issued statutory invoices in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($canSales)
        <h2 class="h5">Sales by date and channel</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Taxable</th>
                        <th>Tax</th>
                        <th>Invoice value</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($salesByDateAndChannel as $row)
                        <tr>
                            <td>{{ $row['invoice_date'] ?: '—' }}</td>
                            <td>{{ $row['channel'] }}</td>
                            <td>{{ $row['status'] }}</td>
                            <td>{{ $row['invoice_count'] }}</td>
                            <td>{{ number_format($row['taxable_value'], 2) }}</td>
                            <td>{{ number_format($row['tax_total'], 2) }}</td>
                            <td>{{ number_format($row['invoice_value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No statutory invoices in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if(($canInvoices || $canGst) && $cancelledInvoices !== [])
        <h2 class="h5">Cancelled invoices</h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Cancelled</th>
                        <th>Channel</th>
                        <th>Source</th>
                        <th>Total</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cancelledInvoices as $invoice)
                        <tr>
                            <td><a href="{{ route('finance.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a></td>
                            <td>{{ $invoice->cancelled_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</td>
                            <td>{{ $invoice->channel->label() }}</td>
                            <td>{{ $invoice->source_id }}</td>
                            <td>{{ number_format((float) $invoice->invoice_value, 2) }}</td>
                            <td>{{ $invoice->cancel_reason ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($canSales && $channelOrders)
        <h2 class="h5">Channel orders</h2>
        <p class="small text-muted">Accepted Desk commerce orders. These are not GST invoices. Eligibility is fail-closed: missing seller GSTIN, place of supply, paid status, or HSN/SAC is reported, not invented.</p>
        <div class="table-responsive mb-4">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Received</th>
                        <th>Channel</th>
                        <th>Source</th>
                        <th>Payment</th>
                        <th>Eligible</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($channelOrders as $order)
                        <tr>
                            <td>{{ $order->order_no }}</td>
                            <td>{{ $order->received_at?->timezone(config('app.timezone'))->format('d M Y') }}</td>
                            <td>{{ $order->channel->label() }}</td>
                            <td class="small">{{ $order->source_id }}</td>
                            <td class="small">{{ $order->payment_status }} {{ $order->payment_method }} {{ $order->payment_reference }}</td>
                            <td>{{ $order->invoice_eligible ? 'Yes' : 'No' }}</td>
                            <td class="small">{{ $order->status->label() }}<div class="text-muted">{{ $order->status_reason }}</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No channel orders in this period. Production ingest is disabled until channel secrets and cutover are approved.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $channelOrders->links() }}
    @endif

    @if($canExport)
        <h2 class="h5 mt-4">CSV exports</h2>
        <p class="small text-muted">Exports use the same date, channel, status, and search filters as this page.</p>
        <ul>
            @if($canInvoices)
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'register'])) }}">Invoice register</a></li>
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'lines'])) }}">Invoice lines</a></li>
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'cancelled'])) }}">Cancelled invoices</a></li>
            @endif
            @if($canGst)
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'gst'])) }}">GST totals</a></li>
            @endif
            @if($canSales)
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'sales'])) }}">Sales by date and channel</a></li>
                <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'channel_orders'])) }}">Channel orders</a></li>
            @endif
            <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'collections'])) }}">Collections / payment references</a></li>
            <li><a href="{{ route('finance.reports.export', array_merge(request()->query(), ['report' => 'summary'])) }}">Period summary</a></li>
        </ul>
    @endif
@endsection
