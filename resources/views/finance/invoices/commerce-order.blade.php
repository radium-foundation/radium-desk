@extends('layouts.app')

@section('title', 'Commerce order '.$order->order_no)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Finance</p>
        <h1 class="h3 mb-1">{{ $order->order_no }}</h1>
        <p class="text-muted mb-0">{{ $order->channel->value }} / {{ $order->source_id }}</p>
    </div>

    @include('finance.partials.workspace-nav', ['active' => 'invoices'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p>Payment: {{ $order->payment_status }} · Status: {{ $order->status->value }}</p>
    <p>Buyer: {{ $order->customer_name }} · GSTIN: {{ $order->buyer_gstin ?: 'B2C' }}</p>
    <p>Statutory invoice: {{ $order->statutoryInvoice?->invoice_number ?: 'Not issued' }}</p>

    @if($order->statutory_invoice_id === null)
        <p class="small mb-3">
            <span class="fw-semibold">{{ $eligibility->staffSummary() }}</span>
            @unless($eligibility->eligible)
                <span class="text-muted">{{ implode('; ', $eligibility->errors) }}</span>
            @endunless
        </p>
    @endif

    @if($canIssue && $order->statutory_invoice_id === null)
        <form method="post" action="{{ route('finance.invoices.commerce-orders.issue', $order) }}">
            @csrf
            <button type="submit" class="btn btn-primary" @disabled(! $eligibility->eligible)>Issue statutory invoice</button>
        </form>
    @endif
@endsection
