@props([
    'refund',
])

@php
    $outcomes = \App\Enums\RefundRevokeCustomerOutcome::cases();
@endphp

<div class="card border-0 shadow-sm mb-3 border-start border-4 border-danger">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0 text-danger">Revoke Refund</h2>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Revoking this completed wallet refund will <strong>reverse the wallet credit</strong>
            through the wallet integration and <strong>automatically restore commercial service</strong>
            on the original order. This is a financial operation — not a simple status change.
        </p>

        <dl class="row small mb-3">
            <dt class="col-sm-5 text-muted">Order ID</dt>
            <dd class="col-sm-7">{{ $refund->order?->order_id ?? '—' }}</dd>
            <dt class="col-sm-5 text-muted">Refund Reference</dt>
            <dd class="col-sm-7">{{ $refund->reference_no }}</dd>
            <dt class="col-sm-5 text-muted">Refund Amount</dt>
            <dd class="col-sm-7">₹{{ number_format($refund->displayAmount(), 2) }}</dd>
            <dt class="col-sm-5 text-muted">Refund Method</dt>
            <dd class="col-sm-7">{{ $refund->approved_refund_method?->label() ?? '—' }}</dd>
            <dt class="col-sm-5 text-muted">Wallet Transaction</dt>
            <dd class="col-sm-7">{{ $refund->effectiveTransactionId() ?: ($refund->execution_reference_no ?: '—') }}</dd>
            <dt class="col-sm-5 text-muted">Current Status</dt>
            <dd class="col-sm-7">@include('refunds.partials.status-badge', ['status' => $refund->status])</dd>
        </dl>

        <form method="POST"
              action="{{ route('refunds.revoke', $refund) }}"
              data-refund-revoke-form
              onsubmit="return confirm('This will reverse the wallet credit and restore commercial service. Continue?');">
            @csrf

            <fieldset class="mb-3">
                <legend class="form-label fw-semibold">What is the customer doing?</legend>
                @foreach($outcomes as $outcome)
                    <div class="form-check mb-2">
                        <input class="form-check-input"
                               type="radio"
                               name="customer_outcome"
                               id="revoke-outcome-{{ $outcome->value }}"
                               value="{{ $outcome->value }}"
                               @checked(old('customer_outcome') === $outcome->value)
                               @disabled(! $outcome->isImplemented())
                               required>
                        <label class="form-check-label @if(! $outcome->isImplemented()) text-muted @endif"
                               for="revoke-outcome-{{ $outcome->value }}">
                            {{ $outcome->label() }}
                            @unless($outcome->isImplemented())
                                <span class="badge text-bg-secondary ms-1">Coming soon</span>
                            @endunless
                        </label>
                    </div>
                @endforeach
                @error('customer_outcome')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </fieldset>

            <div class="mb-3">
                <label for="revoke-reason" class="form-label fw-semibold">Revoke reason</label>
                <textarea class="form-control @error('revoke_reason') is-invalid @enderror"
                          id="revoke-reason"
                          name="revoke_reason"
                          rows="3"
                          maxlength="2000"
                          required>{{ old('revoke_reason') }}</textarea>
                @error('revoke_reason')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @error('refund')
                <div class="alert alert-danger py-2 small">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn btn-danger">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Revoke Refund
            </button>
        </form>
    </div>
</div>
