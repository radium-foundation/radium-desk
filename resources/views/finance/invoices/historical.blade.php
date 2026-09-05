@extends('layouts.app')

@section('title', 'Historical invoices')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">Historical invoice reprint</h1>
        <p class="text-muted mb-0">Look up an existing Admin-era invoice number (for example INV6745886) or a source order ID. This screen never mints, remints, or submits IRN.</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'historical'])

    <form method="get" action="{{ route('finance.invoices.historical') }}" class="row g-2 align-items-end mb-4">
        <div class="col-md-6">
            <label class="form-label" for="historical-invoice-q">Invoice or order ID</label>
            <input id="historical-invoice-q" name="q" value="{{ $query }}" class="form-control" maxlength="64" autocomplete="off">
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Look up</button>
        </div>
    </form>

    @if($result)
        @if($result->eligibility === 'historical_invoice' && $result->canReprint())
            <div class="alert alert-success">
                Historical invoice <strong>{{ $result->invoiceNumber }}</strong> is eligible for reprint. The number is unchanged.
            </div>
            <p>
                <a class="btn btn-outline-primary" href="{{ route('finance.invoices.historical.print', $result->invoiceNumber) }}" target="_blank" rel="noopener">Print</a>
            </p>
            <dl class="row">
                <dt class="col-sm-3">Invoice</dt><dd class="col-sm-9">{{ $result->invoiceNumber }}</dd>
                <dt class="col-sm-3">Order</dt><dd class="col-sm-9">{{ $result->orderId ?: ($result->reprint['ordercode'] ?? '—') }}</dd>
                <dt class="col-sm-3">Customer</dt><dd class="col-sm-9">{{ $result->reprint['buyer']['name'] ?? '—' }}</dd>
                <dt class="col-sm-3">Total</dt><dd class="col-sm-9">{{ $result->reprint['totals']['total'] ?? '—' }}</dd>
            </dl>
        @elseif($result->eligibility === 'paid_without_invoice')
            <div class="alert alert-warning">{{ $result->message ?: 'Paid order without a historical invoice. Do not remint here.' }}</div>
        @elseif($result->eligibility === 'cancelled_or_unpaid')
            <div class="alert alert-warning">{{ $result->message ?: 'Cancelled or unpaid order. No historical reprint.' }}</div>
        @elseif($result->eligibility === 'statutory_invoice')
            <div class="alert alert-info">{{ $result->message }} <a href="{{ route('finance.invoices.index') }}">Statutory invoices</a></div>
        @elseif($result->eligibility === 'source_unavailable')
            <div class="alert alert-danger">{{ $result->message }}</div>
        @elseif($result->eligibility === 'unsupported_source')
            <div class="alert alert-warning">{{ $result->message }}</div>
        @elseif($result->eligibility === 'invalid')
            <div class="alert alert-warning">{{ $result->message }}</div>
        @else
            <div class="alert alert-secondary">{{ $result->message ?: 'Not found on the replacement APIs.' }}</div>
        @endif
    @endif
@endsection
