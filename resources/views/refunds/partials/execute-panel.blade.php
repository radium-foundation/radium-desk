@php
    $isWalletExecution = $refund->approved_refund_method === \App\Enums\ApprovedRefundMethod::Wallet;
    $walletRecovery = $walletRefundRecovery ?? null;
    $isWalletRecovery = $isWalletExecution && ! empty($walletRecovery);
@endphp

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">{{ $isWalletRecovery ? 'Complete Wallet Refund (Recovery)' : 'Execute Refund' }}</h2>
    </div>
    <div class="card-body">
        <div class="alert alert-light border mb-3 py-2 small">
            Desk refund reference <strong>{{ $refund->reference_no }}</strong> was assigned automatically at submission.
        </div>

        @if($refund->approved_refund_method)
            <div class="alert alert-light border mb-3 py-2 small">
                Approved method: <strong>{{ $refund->approved_refund_method->label() }}</strong>
            </div>
        @endif

        @if($isWalletRecovery)
            @include('refunds.partials.wallet-recovery-panel', ['walletRefundRecovery' => $walletRecovery])

            <p class="small text-muted mb-3">
                Use the action below to safely complete this refund using the verified existing Central Wallet credit.
                Desk will not call the spoke integration again and will not create a second credit.
            </p>
        @elseif($isWalletExecution)
            <p class="small text-muted mb-3">
                Completing this refund will credit the customer wallet automatically and record the execution result.
                You do not need to enter wallet transaction IDs or CW references.
            </p>
        @else
            <p class="small text-muted mb-3">
                Record the external payout details (UTR, gateway refund ID, bank reference, etc.), then mark the
                refund completed. Customer and agent notifications run automatically after completion.
            </p>
        @endif

        <form method="POST" action="{{ route('refunds.complete', $refund) }}">
            @csrf

            @unless($isWalletExecution)
                <div class="mb-3">
                    <label for="execution_reference_no" class="form-label">External Payout Reference</label>
                    <input type="text" name="execution_reference_no" id="execution_reference_no"
                           class="form-control @error('execution_reference_no') is-invalid @enderror"
                           value="{{ old('execution_reference_no') }}"
                           placeholder="UTR / bank reference / gateway refund ID">
                    <div class="form-text">
                        Not the Desk refund reference ({{ $refund->reference_no }}).
                    </div>
                    @error('execution_reference_no')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="execution_transaction_id" class="form-label">Transaction ID</label>
                    <input type="text" name="execution_transaction_id" id="execution_transaction_id"
                           class="form-control @error('execution_transaction_id') is-invalid @enderror"
                           value="{{ old('execution_transaction_id') }}"
                           placeholder="Gateway or ledger transaction ID">
                    @error('execution_transaction_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            @endunless

            <div class="mb-3">
                <label for="execution_remarks" class="form-label">Execution Notes</label>
                <textarea name="execution_remarks" id="execution_remarks" rows="3"
                          class="form-control @error('execution_remarks') is-invalid @enderror"
                          placeholder="Optional notes">{{ old('execution_remarks') }}</textarea>
                @error('execution_remarks')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            @error('refund')
                <div class="alert alert-danger py-2 small">{{ $message }}</div>
            @enderror

            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('{{ $isWalletRecovery ? 'Safely complete this refund using the existing Wallet credit?' : 'Mark this refund as completed?' }}');">
                <i class="bi bi-check2-circle me-1"></i>
                @if($isWalletRecovery)
                    Safely Complete Refund
                @elseif($isWalletExecution)
                    Complete Wallet Refund
                @else
                    Mark Refund Completed
                @endif
            </button>
        </form>
    </div>
</div>
