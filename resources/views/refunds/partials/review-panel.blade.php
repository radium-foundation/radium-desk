<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Review Refund</h2>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" id="refundReviewTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="approve-tab" data-bs-toggle="tab" data-bs-target="#approve-panel"
                        type="button" role="tab" aria-controls="approve-panel" aria-selected="true">
                    Approve
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reject-tab" data-bs-toggle="tab" data-bs-target="#reject-panel"
                        type="button" role="tab" aria-controls="reject-panel" aria-selected="false">
                    Reject
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="approve-panel" role="tabpanel" aria-labelledby="approve-tab">
                <form method="POST" action="{{ route('refunds.approve', $refund) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="approve_refund_transaction_id" class="form-label">
                            Refund Transaction ID <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="refund_transaction_id" id="approve_refund_transaction_id"
                               class="form-control @error('refund_transaction_id') is-invalid @enderror"
                               value="{{ old('refund_transaction_id') }}"
                               placeholder="Enter refund transaction ID">
                        @error('refund_transaction_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="approve_review_notes" class="form-label">Review Notes</label>
                        <textarea name="review_notes" id="approve_review_notes" rows="3"
                                  class="form-control @error('review_notes') is-invalid @enderror"
                                  placeholder="Optional notes for approval">{{ old('review_notes') }}</textarea>
                        @error('review_notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-success w-100"
                            onclick="return confirm('Approve this refund request?');">
                        <i class="bi bi-check-lg me-1"></i> Approve Refund
                    </button>
                </form>
            </div>

            <div class="tab-pane fade" id="reject-panel" role="tabpanel" aria-labelledby="reject-tab">
                <form method="POST" action="{{ route('refunds.reject', $refund) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="reject_review_notes" class="form-label">
                            Review Notes <span class="text-danger">*</span>
                        </label>
                        <textarea name="review_notes" id="reject_review_notes" rows="4"
                                  class="form-control @error('review_notes') is-invalid @enderror"
                                  placeholder="Explain why this refund is being rejected..." required>{{ old('review_notes') }}</textarea>
                        @error('review_notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-danger w-100"
                            onclick="return confirm('Reject this refund request?');">
                        <i class="bi bi-x-lg me-1"></i> Reject Refund
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
