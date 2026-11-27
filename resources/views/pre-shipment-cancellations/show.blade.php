@extends('layouts.app')

@section('title', $case->reference_no)

@section('content')
    <div class="container py-4">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0">{{ $case->reference_no }}</h1>
            <div class="d-flex gap-2">
                @if ($centralNavVisible)
                    <a href="{{ route('pre-shipment-cancellations.central-queue') }}" class="btn btn-outline-secondary btn-sm">Central queue</a>
                @endif
                @if ($case->order)
                    <a href="{{ route('orders.show', $case->order) }}" class="btn btn-outline-secondary btn-sm">Order {{ $case->source_id }}</a>
                @endif
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card card-body small h-100">
                    <h2 class="h6">Case status</h2>
                    <dl class="row mb-0">
                        <dt class="col-5">Status</dt>
                        <dd class="col-7"><code>{{ $case->status->value }}</code></dd>
                        <dt class="col-5">Service case sync</dt>
                        <dd class="col-7"><code>{{ $case->service_case_sync_status?->value ?? 'n/a' }}</code></dd>
                        <dt class="col-5">Split tender</dt>
                        <dd class="col-7">{{ $case->split_tender ? 'Yes' : 'No' }}</dd>
                        <dt class="col-5">Execution hold</dt>
                        <dd class="col-7">{{ $case->execution_hold ? ($case->execution_hold_reason ?? 'Yes') : 'No' }}</dd>
                    </dl>
                    @if ($case->split_tender)
                        <div class="alert alert-warning mt-2 mb-0 py-2">
                            Cashfree and Wallet amounts require manual Finance review and execution. This screen does not run refunds.
                        </div>
                    @endif
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card card-body small h-100">
                    <h2 class="h6">Amounts (stored at submit)</h2>
                    <dl class="row mb-0">
                        <dt class="col-6">Order total</dt>
                        <dd class="col-6">₹{{ number_format((float) $case->order_total_amount, 2) }}</dd>
                        <dt class="col-6">External / Cashfree</dt>
                        <dd class="col-6">₹{{ number_format((float) $case->external_tender_amount, 2) }}</dd>
                        <dt class="col-6">Wallet</dt>
                        <dd class="col-6">₹{{ number_format((float) $case->wallet_tender_amount, 2) }}</dd>
                        <dt class="col-6">Requested refund</dt>
                        <dd class="col-6">₹{{ number_format((float) $case->requested_refund_amount, 2) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        @include('pre-shipment-cancellations.partials.operator-metadata', ['case' => $case])

        @if ($canRetryIncident && $case->service_case_sync_status?->value !== 'succeeded')
            <form method="POST" action="{{ route('pre-shipment-cancellations.retry-incident', $case) }}" class="mt-3">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">Queue incident close retry</button>
            </form>
        @endif

        <section class="mt-4 d-flex flex-wrap gap-2">
            @if ($canApprove && $case->status->value === 'finance_review')
                <form method="POST" action="{{ route('pre-shipment-cancellations.finance-approve', $case) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Finance approve</button>
                </form>
            @endif
            @if ($canReject && ! $case->status->isTerminal())
                <form method="POST" action="{{ route('pre-shipment-cancellations.finance-reject', $case) }}" class="d-flex gap-2 align-items-start">
                    @csrf
                    <input type="text" name="rejection_reason" class="form-control form-control-sm" placeholder="Rejection reason" required maxlength="500">
                    <button type="submit" class="btn btn-outline-danger btn-sm">Reject</button>
                </form>
            @endif
            @if ($canLinkRefund && in_array($case->status->value, ['finance_approved'], true) && $case->refund_request_id === null)
                <form method="POST" action="{{ route('pre-shipment-cancellations.link-refund', $case) }}">
                    @csrf
                    <button type="submit" class="btn btn-warning btn-sm">Link refund request (no auto execution)</button>
                </form>
            @endif
        </section>
    </div>
@endsection