@props([
    'refund',
])

<div class="card border-0 shadow-sm mb-3 border-start border-4 border-warning">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0 text-warning-emphasis">Re-route Payout Method</h2>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            This refund was approved for wallet credit, but no automated wallet destination exists for this order source.
            Re-route it to <strong>Cashfree / original payment method</strong> so operations can complete it manually.
            This does not execute the refund or call Cashfree.
        </p>

        <dl class="row small mb-3">
            <dt class="col-sm-5 text-muted">Order ID</dt>
            <dd class="col-sm-7">{{ $refund->order?->order_id ?? '—' }}</dd>
            <dt class="col-sm-5 text-muted">Refund Reference</dt>
            <dd class="col-sm-7">{{ $refund->reference_no }}</dd>
            <dt class="col-sm-5 text-muted">Refund Amount</dt>
            <dd class="col-sm-7">₹{{ number_format($refund->displayAmount(), 2) }}</dd>
            <dt class="col-sm-5 text-muted">Current Approved Method</dt>
            <dd class="col-sm-7">{{ $refund->approved_refund_method?->label() ?? '—' }}</dd>
            <dt class="col-sm-5 text-muted">Customer Preference</dt>
            <dd class="col-sm-7">{{ $refund->customer_preferred_method?->label() ?? '—' }}</dd>
            <dt class="col-sm-5 text-muted">Current Status</dt>
            <dd class="col-sm-7">@include('refunds.partials.status-badge', ['status' => $refund->status])</dd>
        </dl>

        <form method="POST"
              action="{{ route('refunds.reroute-execution-method', $refund) }}"
              onsubmit="return confirm('Re-route this refund from wallet to Cashfree? This does not execute the payout.');">
            @csrf

            <div class="mb-3">
                <label for="reroute-reason" class="form-label fw-semibold">Re-route reason</label>
                <textarea class="form-control @error('reroute_reason') is-invalid @enderror"
                          id="reroute-reason"
                          name="reroute_reason"
                          rows="3"
                          maxlength="2000"
                          required>{{ old('reroute_reason') }}</textarea>
                @error('reroute_reason')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @error('refund')
                <div class="alert alert-danger py-2 small">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn btn-warning">
                <i class="bi bi-arrow-left-right me-1"></i> Re-route to Cashfree
            </button>
        </form>
    </div>
</div>
