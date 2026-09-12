@extends('layouts.app')

@section('title', 'Supplier Invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Supplier Invoices</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'supplier_invoices'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Vendor</th>
                        <th>PO</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->supplier_invoice_number }}</td>
                            <td>{{ $invoice->vendor->business_name }}</td>
                            <td>{{ $invoice->purchaseOrder->po_number }}</td>
                            <td>{{ $invoice->invoice_date->format('Y-m-d') }}</td>
                            <td>₹{{ number_format((float) $invoice->invoice_amount, 2) }}</td>
                            <td>{{ $invoice->payment_status->label() }}</td>
                            <td><a href="{{ route('purchasing.supplier-invoices.show', $invoice) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted p-4">No supplier invoices yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $invoices->links() }}</div>
@endsection
