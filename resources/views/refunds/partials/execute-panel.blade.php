<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Execute Refund</h2>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            Record the payout details, then mark the refund completed. Customer and agent notifications
            run automatically after completion.
        </p>

        @if($refund->approved_refund_method)
            <div class="alert alert-light border mb-3 py-2 small">
                Approved method: <strong>{{ $refund->approved_refund_method->label() }}</strong>
            </div>
        @endif

        <form method="POST" action="{{ route('refunds.complete', $refund) }}">
            @csrf

            <div class="mb-3">
                <label for="execution_reference_no" class="form-label">Reference Number</label>
                <input type="text" name="execution_reference_no" id="execution_reference_no"
                       class="form-control @error('execution_reference_no') is-invalid @enderror"
                       value="{{ old('execution_reference_no') }}"
                       placeholder="UTR / bank reference / wallet ref">
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

            <div class="mb-3">
                <label for="execution_remarks" class="form-label">Execution Notes</label>
                <textarea name="execution_remarks" id="execution_remarks" rows="3"
                          class="form-control @error('execution_remarks') is-invalid @enderror"
                          placeholder="Optional notes">{{ old('execution_remarks') }}</textarea>
                @error('execution_remarks')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('Mark this refund as completed?');">
                <i class="bi bi-check2-circle me-1"></i> Mark Refund Completed
            </button>
        </form>
    </div>
</div>
