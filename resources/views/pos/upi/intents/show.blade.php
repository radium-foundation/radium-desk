@extends('layouts.app')

@section('title', $intent->public_ref)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
            <h1 class="h3 mb-1">{{ $intent->public_ref }}</h1>
            <p class="text-muted mb-0">{{ $intent->status->label() }} · {{ $intent->branch?->code }}</p>
        </div>
        <a href="{{ route('pos.upi.intents.index') }}" class="btn btn-outline-secondary">All pending UPI</a>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'upi'])

    <div class="alert alert-warning">
        This QR is a payment instruction only. It is <strong>not</strong> proof that money was received. Do not complete the sale until an authorized user checks the live bank account and enters the UTR.
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <canvas id="upi-qr-canvas" data-upi-qr="{{ $intent->upi_uri }}" width="240" height="240" aria-label="UPI QR"></canvas>
                    <p class="small text-muted mt-3 mb-1">Ask the customer to pay this exact amount to the selected company account.</p>
                    <p class="small font-monospace text-break mb-0">{{ $intent->tr }}</p>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6 text-muted">Collect</h2>
                    <div class="fs-4 fw-semibold">{{ number_format((float) $intent->amount, 2) }} {{ $intent->currency }}</div>
                    <div class="mt-2">{{ $intent->receivingAccountLabel() }}</div>
                    <div class="small text-muted">Bank name and last four only.</div>
                    <div class="mt-3">
                        <div>{{ $intent->customer_name }}</div>
                        <div>{{ $intent->customer_phone }}</div>
                    </div>
                    <div class="small text-muted mt-2">Intent {{ $intent->public_ref }} · TR {{ $intent->tr }}</div>
                    @if($intent->expires_at)
                        <div class="small text-muted">Expires {{ $intent->expires_at->timezone(config('app.timezone'))->format('d M Y H:i') }}</div>
                    @endif
                </div>
            </div>

            @if($intent->status === \App\Enums\PosPaymentIntentStatus::Pending)
                <div class="d-flex flex-wrap gap-2 mb-3">
                    @if($canVerify)
                        <a href="{{ route('pos.upi.payments.show', $intent) }}" class="btn btn-primary">Verify bank credit</a>
                    @endif
                    @if($canAbandon)
                        <form method="POST" action="{{ route('pos.upi.intents.abandon', $intent) }}" data-once-submit>
                            @csrf
                            <input type="hidden" name="reason" value="Abandoned from pending screen">
                            <button class="btn btn-outline-danger">Abandon unpaid</button>
                        </form>
                        <form method="POST" action="{{ route('pos.upi.intents.cancel', $intent) }}" data-once-submit>
                            @csrf
                            <input type="hidden" name="reason" value="Cancelled from pending screen">
                            <button class="btn btn-outline-secondary">Cancel unpaid</button>
                        </form>
                    @endif
                </div>
                <p class="small text-muted">If the customer has not paid, leave this pending or abandon it. Do not mark it verified.</p>
            @elseif($intent->sale)
                <a href="{{ route('pos.sales.show', $intent->sale) }}" class="btn btn-outline-secondary">Open sale {{ $intent->sale->sale_no }}</a>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/pos-upi-qr.js') }}"></script>
    <script>
        document.querySelectorAll('form[data-once-submit]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = '1';
                form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
                    button.disabled = true;
                });
            });
        });
    </script>
@endpush
