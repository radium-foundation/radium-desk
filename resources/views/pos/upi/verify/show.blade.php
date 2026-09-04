@extends('layouts.app')

@section('title', 'Verify '.$intent->public_ref)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
        <h1 class="h3 mb-1">Verify {{ $intent->public_ref }}</h1>
        <p class="text-muted mb-0">Check the live bank statement for this credit. Do not use a screenshot as proof.</p>
    </div>
    @include('pos.partials.workspace-nav', ['active' => 'upi-verify'])

    <div class="alert alert-warning">
        Displaying the QR does not confirm payment. Complete this form only after you have checked the actual bank account.
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-muted">Intent</h2>
                    <div class="fs-4 fw-semibold">{{ number_format((float) $intent->amount, 2) }} {{ $intent->currency }}</div>
                    <div class="mt-2">{{ $intent->receivingAccountLabel() }}</div>
                    <div class="small text-muted">{{ $intent->receivingBankAccount?->account_name }}</div>
                    <div class="mt-3">
                        <div>{{ $intent->customer_name }}</div>
                        <div>{{ $intent->customer_phone }}</div>
                    </div>
                    <div class="small text-muted mt-2">TR {{ $intent->tr }}</div>
                    <div class="small text-muted">{{ $intent->branch?->code }} · {{ $intent->created_at?->timezone(config('app.timezone'))->format('d M Y H:i') }}</div>
                    @if($intent->status !== \App\Enums\PosPaymentIntentStatus::Pending)
                        <div class="mt-3">Status: {{ $intent->status->label() }}</div>
                        @if($intent->sale)
                            <a href="{{ route('pos.sales.show', $intent->sale) }}">Open sale {{ $intent->sale->sale_no }}</a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            @if($intent->status === \App\Enums\PosPaymentIntentStatus::Pending)
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6">Bank verification</h2>
                        <form method="POST" action="{{ route('pos.upi.payments.verify', $intent) }}" data-once-submit>
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="confirmed_amount">Amount seen in the bank</label>
                                <input type="number" step="0.01" min="0.01" name="confirmed_amount" id="confirmed_amount" class="form-control" required value="{{ old('confirmed_amount') }}">
                                @error('confirmed_amount')<div class="text-danger small">{{ $message }}</div>@enderror
                                @error('amount')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="utr">UTR / bank reference</label>
                                <input type="text" name="utr" id="utr" class="form-control" required maxlength="64" value="{{ old('utr') }}" autocomplete="off">
                                @error('utr')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="bank_checked" value="1" id="bank_checked" required @checked(old('bank_checked'))>
                                <label class="form-check-label" for="bank_checked">I checked the live bank account for this credit.</label>
                                @error('bank_checked')<div class="text-danger small">{{ $message }}</div>@enderror
                            </div>
                            @error('intent')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                            <button class="btn btn-primary" type="submit">Confirm and complete sale</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
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
