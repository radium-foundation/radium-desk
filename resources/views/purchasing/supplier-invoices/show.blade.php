@extends('layouts.app')

@section('title', $invoice->supplier_invoice_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">{{ $invoice->supplier_invoice_number }}</h1>
            <p class="text-muted mb-0">{{ $invoice->vendor->business_name }} · {{ $invoice->payment_status->label() }}</p>
        </div>
        @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_PAYMENT)
            <a href="{{ route('purchasing.purchase-payments.create', $invoice) }}" class="btn btn-primary">Record payment</a>
        @endcan
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'supplier_invoices'])

    <div class="card border-0 shadow-sm p-4 mb-3">
        <dl class="row mb-0">
            <dt class="col-sm-3">PO</dt><dd class="col-sm-9">{{ $invoice->purchaseOrder->po_number }}</dd>
            <dt class="col-sm-3">Invoice date</dt><dd class="col-sm-9">{{ $invoice->invoice_date->format('Y-m-d') }}</dd>
            <dt class="col-sm-3">Amount</dt><dd class="col-sm-9">₹{{ number_format((float) $invoice->invoice_amount, 2) }}</dd>
            <dt class="col-sm-3">Paid</dt><dd class="col-sm-9">₹{{ number_format((float) $invoice->amount_paid, 2) }}</dd>
        </dl>
    </div>

    @if($invoice->documents->isNotEmpty())
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Documents</div>
            <ul class="list-group list-group-flush">
                @foreach($invoice->documents as $document)
                    <li class="list-group-item">
                        <a href="{{ route('purchasing.supplier-invoices.documents.download', [$invoice, $document]) }}">{{ $document->original_filename }}</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Payments</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoice->payments as $payment)
                        <tr>
                            <td>{{ $payment->payment_date->format('Y-m-d') }}</td>
                            <td>{{ $payment->payment_method }}</td>
                            <td>{{ $payment->transaction_reference ?? '—' }}</td>
                            <td class="text-end">₹{{ number_format((float) $payment->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted p-4">No payments recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
