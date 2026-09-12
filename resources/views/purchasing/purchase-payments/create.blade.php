@extends('layouts.app')

@section('title', 'Record payment')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Payment for {{ $invoice->supplier_invoice_number }}</h1>
        <p class="text-muted">Balance ₹{{ number_format((float) $invoice->invoice_amount - (float) $invoice->amount_paid, 2) }}</p>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_payments'])

    <form method="POST" action="{{ route('purchasing.purchase-payments.store', $invoice) }}" class="card border-0 shadow-sm p-4">
        @csrf
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Payment date</label>
                <input type="date" name="payment_date" class="form-control" value="{{ now()->toDateString() }}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Amount</label>
                <input type="number" step="0.01" name="amount" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Method</label>
                <input type="text" name="payment_method" class="form-control" placeholder="NEFT / UPI / Cheque" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Transaction reference</label>
                <input type="text" name="transaction_reference" class="form-control">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
        </div>
        <button class="btn btn-primary mt-3">Record payment</button>
    </form>
@endsection
