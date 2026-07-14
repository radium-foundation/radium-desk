<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Review Refund</h2>
    </div>
    <div class="card-body">
        @if($refund->customer_preferred_method)
            <div class="alert alert-light border mb-3 py-2 small">
                Customer preference:
                <strong>{{ $refund->customer_preferred_method->label() }}</strong>
                <span class="text-muted">(advisory — select the actual method below)</span>
            </div>
        @endif

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
                        <label for="approved_refund_method" class="form-label">
                            Refund Method <span class="text-danger">*</span>
                        </label>
                        <select name="approved_refund_method" id="approved_refund_method"
                                class="form-select @error('approved_refund_method') is-invalid @enderror" required>
                            <option value="">Select method</option>
                            @foreach($approvedMethods as $method)
                                <option value="{{ $method->value }}" @selected(old('approved_refund_method') === $method->value)>
                                    {{ $method->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('approved_refund_method')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="deduction_profile_key" class="form-label">Refund Profile</label>
                        <select name="deduction_profile_key" id="deduction_profile_key" class="form-select">
                            @foreach($deductionProfiles as $profile)
                                <option value="{{ $profile->value }}"
                                    @selected(old('deduction_profile_key', $refund->deduction_profile_key?->value ?? 'full_refund') === $profile->value)>
                                    {{ $profile->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @include('refunds.partials.calculation-fields', [
                        'prefix' => 'approve',
                        'refund' => $refund,
                        'calculation' => $calculation,
                        'differenceReasons' => $differenceReasons,
                    ])

                    <div class="mb-3">
                        <label for="approve_review_notes" class="form-label">Review Notes</label>
                        <textarea name="review_notes" id="approve_review_notes" rows="2"
                                  class="form-control @error('review_notes') is-invalid @enderror"
                                  placeholder="Optional notes for approval">{{ old('review_notes') }}</textarea>
                        @error('review_notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-success w-100"
                            onclick="return confirm('Approve this refund and move it to Pending Execution?');">
                        <i class="bi bi-check-lg me-1"></i> Approve Refund
                    </button>
                </form>
            </div>

            <div class="tab-pane fade" id="reject-panel" role="tabpanel" aria-labelledby="reject-tab">
                <form method="POST" action="{{ route('refunds.reject', $refund) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="reject_review_notes" class="form-label">
                            Reject Reason <span class="text-danger">*</span>
                        </label>
                        <textarea name="review_notes" id="reject_review_notes" rows="4"
                                  class="form-control @error('review_notes') is-invalid @enderror"
                                  placeholder="Explain why this refund is being rejected" required>{{ old('review_notes') }}</textarea>
                        @error('review_notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-outline-danger w-100"
                            onclick="return confirm('Reject this refund request?');">
                        <i class="bi bi-x-lg me-1"></i> Reject Refund
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
