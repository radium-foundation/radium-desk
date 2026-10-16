@props([
    'refund',
    'opm' => [],
])

@php
    $conversion = $opm['active'] ?? null;
    $canRequest = (bool) ($opm['can_request'] ?? false);
    $blockReason = $opm['request_block_reason'] ?? null;
@endphp

<div class="card border-0 shadow-sm mb-3 border-start border-4 border-info">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Wallet → original payment method (OPM)</h2>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Desk manages conversion requests, approvals, Cashfree verification, and final status.
            Funds stay reserved until verification and wallet reversal succeed, or finance authorizes release.
        </p>

        @if($conversion)
            <dl class="row small mb-3">
                <dt class="col-sm-5 text-muted">Conversion ID</dt>
                <dd class="col-sm-7"><code>{{ $conversion->public_id }}</code></dd>
                <dt class="col-sm-5 text-muted">Status</dt>
                <dd class="col-sm-7"><span class="badge text-bg-secondary">{{ $conversion->status?->value }}</span></dd>
                <dt class="col-sm-5 text-muted">Amount</dt>
                <dd class="col-sm-7">₹{{ number_format((float) $conversion->amount, 2) }}</dd>
                <dt class="col-sm-5 text-muted">Wallet credit source</dt>
                <dd class="col-sm-7">{{ $conversion->wallet_credit_source?->label() ?? '—' }}</dd>
                @if($conversion->failure_message)
                    <dt class="col-sm-5 text-muted">Last issue</dt>
                    <dd class="col-sm-7 text-danger">{{ $conversion->failure_message }}</dd>
                @endif
            </dl>

            @can('refunds.wallet_opm_conversion.approve')
                @if($conversion->status === \App\Enums\WalletRefundOpmConversionStatus::PendingApproval)
                    <form method="POST" action="{{ route('wallet-opm-conversions.approve', $conversion) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">Approve &amp; reserve</button>
                    </form>
                    <form method="POST" action="{{ route('wallet-opm-conversions.reject', $conversion) }}" class="d-inline ms-2">
                        @csrf
                        <label class="visually-hidden" for="opm-reject-reason">Reason</label>
                        <input type="text" name="reason" id="opm-reject-reason" class="form-control form-control-sm d-inline-block w-auto" placeholder="Rejection reason" required>
                        <button type="submit" class="btn btn-sm btn-outline-danger">Reject</button>
                    </form>
                @endif
            @endcan

            @can('refunds.wallet_opm_conversion.payout')
                @if($conversion->status === \App\Enums\WalletRefundOpmConversionStatus::Reserved)
                    <form method="POST" action="{{ route('wallet-opm-conversions.record-payout', $conversion) }}" class="mt-3">
                        @csrf
                        <p class="small fw-semibold mb-2">After Cashfree portal payout (manual)</p>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" name="cashfree_order_id" class="form-control form-control-sm" placeholder="Cashfree order id" value="{{ $refund->order?->order_id }}" required>
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="cashfree_refund_id" class="form-control form-control-sm" placeholder="Cashfree refund id" required>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="cashfree_refund_amount" class="form-control form-control-sm" placeholder="Amount" value="{{ $conversion->amount }}" required>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-sm btn-warning w-100">Record</button>
                            </div>
                        </div>
                    </form>
                @endif
                @if(in_array($conversion->status, [\App\Enums\WalletRefundOpmConversionStatus::PayoutRecorded, \App\Enums\WalletRefundOpmConversionStatus::ReconciliationHold], true))
                    <form method="POST" action="{{ route('wallet-opm-conversions.verify', $conversion) }}" class="mt-2">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-primary">Retry verify &amp; finalize</button>
                    </form>
                @endif
            @endcan

            @can('refunds.wallet_opm_conversion.release')
                @if($conversion->status === \App\Enums\WalletRefundOpmConversionStatus::Reserved)
                    <form method="POST" action="{{ route('wallet-opm-conversions.release', $conversion) }}" class="mt-3">
                        @csrf
                        <input type="text" name="reason" class="form-control form-control-sm mb-2" placeholder="Release reason (no Cashfree payout)" required>
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Authorize release</button>
                    </form>
                @endif
            @endcan
        @elseif($canRequest)
            <form method="POST" action="{{ route('refunds.wallet-opm-conversions.store', $refund) }}"
                  onsubmit="return confirm('Submit a full wallet-to-OPM conversion request for {{ $refund->reference_no }}?');">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">Request full OPM conversion</button>
            </form>
        @elseif($blockReason)
            <p class="small text-muted mb-0">{{ $blockReason }}</p>
        @endif
    </div>
</div>
