@php
    use App\Enums\OnlineReturnRefundAllocationStatus;
    use App\Enums\OnlineReturnRefundAllocationDestination;
    use App\Enums\ReturnExchangeCaseSourceKind;

    $isOnline = in_array($case->source_kind, [
        ReturnExchangeCaseSourceKind::EcommerceOrder,
        ReturnExchangeCaseSourceKind::EcommerceFulfilment,
    ], true);
@endphp

@if ($isOnline)
    <div class="card mb-3 border-secondary">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Online refund allocation (split tender)</h2>
        </div>
        <div class="card-body small">
            <p class="text-muted mb-2">
                Finance approval records destination amounts only — no wallet credit, Cashfree payout, or RefundRequest is created here.
                Direct refund request remains blocked until tender can be allocated safely.
            </p>

            @if ($case->onlineRefundAllocation)
                <dl class="row mb-2">
                    <dt class="col-5 col-sm-3">Reference</dt>
                    <dd class="col-7 col-sm-9">{{ $case->onlineRefundAllocation->reference_no }}</dd>
                    <dt class="col-5 col-sm-3">Status</dt>
                    <dd class="col-7 col-sm-9">{{ $case->onlineRefundAllocation->status->label() }}</dd>
                    <dt class="col-5 col-sm-3">Requested total</dt>
                    <dd class="col-7 col-sm-9">₹{{ number_format((float) $case->onlineRefundAllocation->requested_total, 2) }}</dd>
                    <dt class="col-5 col-sm-3">Verified cap</dt>
                    <dd class="col-7 col-sm-9">₹{{ number_format((float) $case->onlineRefundAllocation->max_allocatable_amount, 2) }}</dd>
                </dl>
                <ul class="list-unstyled mb-2">
                    @foreach ($case->onlineRefundAllocation->lines as $line)
                        <li>
                            Line {{ $line->line_no }}:
                            {{ $line->destination->label() }}
                            — ₹{{ number_format((float) $line->amount, 2) }}
                            @if ($line->execution_reference)
                                <span class="text-muted">({{ $line->execution_reference }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if (
                    ($canReviewOnlineRefundAllocation ?? false)
                    && $case->onlineRefundAllocation->status === OnlineReturnRefundAllocationStatus::PendingFinanceReview
                )
                    <form method="post" action="{{ route('online-return-refund-allocations.review', $case->onlineRefundAllocation) }}" class="vstack gap-2">
                        @csrf
                        <label class="form-label fw-semibold" for="ora-review-reason">Finance notes</label>
                        <textarea id="ora-review-reason" name="review_reason" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('review_reason') }}</textarea>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-outline-success">Approve allocation</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-sm btn-outline-danger">Reject</button>
                        </div>
                    </form>
                @endif
            @elseif ($canSubmitOnlineRefundAllocation ?? false)
                <form method="post" action="{{ route('return-cases.online-refund-allocation.store', $case) }}" class="vstack gap-2">
                    @csrf
                    <p class="mb-1">For wallet + external (split) orders after inspection and recorded tax outcome.</p>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label">Destination</label>
                            <select name="lines[0][destination]" class="form-select form-select-sm">
                                @foreach (OnlineReturnRefundAllocationDestination::supported() as $dest)
                                    <option value="{{ $dest->value }}">{{ $dest->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Amount (₹)</label>
                            <input type="text" name="lines[0][amount]" class="form-control form-control-sm" required inputmode="decimal">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Reference (bank/UPI if needed)</label>
                            <input type="text" name="lines[0][execution_reference]" class="form-control form-control-sm" maxlength="128">
                        </div>
                    </div>
                    <label class="form-label" for="ora-requester-remarks">Internal remarks (optional)</label>
                    <textarea id="ora-requester-remarks" name="requester_remarks" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('requester_remarks') }}</textarea>
                    <button type="submit" class="btn btn-sm btn-primary align-self-start">Submit allocation for Finance review</button>
                </form>
            @else
                <p class="mb-0 text-muted">No Finance allocation on file.</p>
            @endif
        </div>
    </div>
@endif
