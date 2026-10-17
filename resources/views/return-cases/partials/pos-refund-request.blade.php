@php
    use App\Enums\PosReturnRefundRequestStatus;
    use App\Enums\ReturnExchangeCaseSourceKind;

    $isPos = $case->source_kind === ReturnExchangeCaseSourceKind::PosSale;
@endphp

@if ($isPos)
    <div class="card mb-3 border-secondary">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">POS refund request (internal)</h2>
        </div>
        <div class="card-body small">
            <p class="text-muted mb-2">
                Request and Finance review only — no payout, wallet, or tax actions on this screen.
            </p>

            @if ($case->posRefundRequest)
                <dl class="row mb-0">
                    <dt class="col-5 col-sm-3">Reference</dt>
                    <dd class="col-7 col-sm-9">{{ $case->posRefundRequest->reference_no }}</dd>
                    <dt class="col-5 col-sm-3">Status</dt>
                    <dd class="col-7 col-sm-9">{{ $case->posRefundRequest->status->label() }}</dd>
                    <dt class="col-5 col-sm-3">Amount</dt>
                    <dd class="col-7 col-sm-9">₹{{ number_format((float) $case->posRefundRequest->amount, 2) }}</dd>
                    <dt class="col-5 col-sm-3">Payment method</dt>
                    <dd class="col-7 col-sm-9">{{ $case->posRefundRequest->payment_method }}</dd>
                    <dt class="col-5 col-sm-3">Tax evidence</dt>
                    <dd class="col-7 col-sm-9">{{ $case->posRefundRequest->tax_evidence_type->label() }}</dd>
                    <dt class="col-5 col-sm-3">Submitted</dt>
                    <dd class="col-7 col-sm-9">
                        {{ $case->posRefundRequest->requested_at?->format('d M Y H:i') }}
                        @if ($case->posRefundRequest->requester)
                            by {{ $case->posRefundRequest->requester->name }}
                        @endif
                    </dd>
                    @if ($case->posRefundRequest->reviewed_at)
                        <dt class="col-5 col-sm-3">Finance decision</dt>
                        <dd class="col-7 col-sm-9">
                            {{ $case->posRefundRequest->reviewed_at->format('d M Y H:i') }}
                            @if ($case->posRefundRequest->reviewer)
                                by {{ $case->posRefundRequest->reviewer->name }}
                            @endif
                            @if ($case->posRefundRequest->review_reason)
                                <br><span class="text-muted">{{ $case->posRefundRequest->review_reason }}</span>
                            @endif
                        </dd>
                    @endif
                </dl>

                @if (
                    ($canReviewPosRefund ?? false)
                    && $case->posRefundRequest->status === PosReturnRefundRequestStatus::PendingFinanceReview
                )
                    <hr class="my-3">
                    <form method="post" action="{{ route('pos-return-refund-requests.review', $case->posRefundRequest) }}" class="vstack gap-2">
                        @csrf
                        <label class="form-label fw-semibold" for="pos-refund-review-reason">Finance notes (optional on approve)</label>
                        <textarea id="pos-refund-review-reason" name="review_reason" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('review_reason') }}</textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-outline-success">Approve for Finance processing</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                        </div>
                    </form>
                @endif
            @elseif ($canSubmitPosRefund ?? false)
                <form method="post" action="{{ route('return-cases.pos-refund-request.store', $case) }}" class="vstack gap-2">
                    @csrf
                    <p class="mb-1">Submit after goods are inspected and statutory cancellation or credit note is recorded.</p>
                    <label class="form-label" for="pos-refund-requester-remarks">Internal remarks (optional)</label>
                    <textarea id="pos-refund-requester-remarks" name="requester_remarks" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('requester_remarks') }}</textarea>
                    <button type="submit" class="btn btn-sm btn-primary align-self-start">Submit for Finance review</button>
                </form>
            @else
                <p class="mb-0 text-muted">No POS refund request on file.</p>
            @endif
        </div>
    </div>
@endif
