@extends('layouts.app')

@section('title', 'Customer Payments')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Customer payments</h1>
        <p class="text-muted mb-0">Record inbound payments against service statutory invoices. No finance journal posting.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'payments'])

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

    @if($canRecord)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Record payment &amp; allocation</h2>
                <form method="POST" action="{{ route('finance.payments.store') }}" class="row g-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="col-md-6">
                        <label class="form-label">Service invoice</label>
                        <select name="statutory_invoice_id" class="form-select" required id="payment-invoice-select">
                            <option value="">Select invoice</option>
                            @foreach($serviceInvoices as $invoice)
                                <option value="{{ $invoice->id }}" data-customer="{{ $invoice->buyer_phone }}" @selected($selectedInvoice && $selectedInvoice->id === $invoice->id)>
                                    {{ $invoice->invoice_number }} — {{ $invoice->buyer_name }} — ₹{{ number_format((float) $invoice->invoice_value, 2) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Customer ID (phone lookup)</label>
                        <input type="number" name="customer_id" class="form-control" required value="{{ old('customer_id', $selectedCustomerId) }}">
                        <div class="form-text">Use inventory customer linked to the invoice buyer phone.</div>
                    </div>
                    @if($outstanding !== null)
                        <div class="col-12"><div class="alert alert-info mb-0">Outstanding on selected invoice: <strong>₹{{ number_format($outstanding, 2) }}</strong></div></div>
                    @endif
                    <div class="col-md-3">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" class="form-control" required value="{{ old('amount') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Method</label>
                        <input type="text" name="method" class="form-control" required value="{{ old('method', 'Bank Transfer') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Payment date</label>
                        <input type="date" name="payment_date" class="form-control" required value="{{ old('payment_date', now()->toDateString()) }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control" value="{{ old('reference') }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary">Record payment</button></div>
                </form>
            </div>
        </div>
    @else
        <div class="alert alert-secondary">You can view this screen but cannot record payments.</div>
    @endif
@endsection
