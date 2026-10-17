@php
    use App\Enums\OnlineReturnRefundAllocationLineExecutionStatus;
    use App\Enums\OnlineReturnRefundAllocationStatus;
    use App\Enums\OnlineReturnRefundAllocationDestination;
    use App\Enums\ReturnExchangeCaseSourceKind;
    use Illuminate\Support\Str;

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
                Finance approval records destination amounts. Execution (wallet credit or manual payout evidence) is separate, disabled by default, and never creates a generic RefundRequest.
                Direct refund request remains blocked until tender can be allocated safely.
            </p>
            @if (! ($onlineRefundAllocationExecutionEnabled ?? false))
                <p class="text-warning small mb-2">Allocation execution is disabled in this environment.</p>
            @endif

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
                        @php
                            $latestExecution = $line->executions->sortByDesc('id')->first();
                            $succeeded = $line->successfulExecution;
                        @endphp
                        <li class="mb-2">
                            <div>
                                Line {{ $line->line_no }}:
                                {{ $line->destination->label() }}
                                — ₹{{ number_format((float) $line->amount, 2) }}
                                @if ($line->execution_reference)
                                    <span class="text-muted">(planned ref: {{ $line->execution_reference }})</span>
                                @endif
                            </div>
                            @if ($succeeded)
                                <span class="badge bg-success">Executed</span>
                                @if ($succeeded->execution_reference)
                                    <span class="text-muted small">Ref {{ $succeeded->execution_reference }}</span>
                                @endif
                                @if ($succeeded->transaction_id)
                                    <span class="text-muted small">Txn {{ $succeeded->transaction_id }}</span>
                                @endif
                            @elseif ($latestExecution)
                                <span class="badge bg-secondary">{{ $latestExecution->status->label() }}</span>
                                @if ($latestExecution->held_reason)
                                    <span class="text-muted small d-block">{{ $latestExecution->held_reason }}</span>
                                @endif
                                @if ($latestExecution->failure_reason)
                                    <span class="text-danger small d-block">{{ $latestExecution->failure_reason }}</span>
                                @endif
                            @elseif ($case->onlineRefundAllocation->status === OnlineReturnRefundAllocationStatus::Approved)
                                <span class="badge bg-light text-dark border">Approved — not executed</span>
                            @endif

                            @if (
                                ($canExecuteOnlineRefundAllocation ?? false)
                                && ($onlineRefundAllocationExecutionEnabled ?? false)
                                && $case->onlineRefundAllocation->status === OnlineReturnRefundAllocationStatus::Approved
                                && $succeeded === null
                                && ($latestExecution === null || in_array($latestExecution->status, [
                                    OnlineReturnRefundAllocationLineExecutionStatus::Failed,
                                    OnlineReturnRefundAllocationLineExecutionStatus::ManualPending,
                                ], true))
                            )
                                <form method="post" action="{{ route('online-return-refund-allocation-lines.execute', $line) }}" class="vstack gap-1 mt-1 border rounded p-2 bg-light">
                                    @csrf
                                    <input type="hidden" name="idempotency_key" value="{{ Str::uuid() }}">
                                    @if (in_array($line->destination, [
                                        OnlineReturnRefundAllocationDestination::Cashfree,
                                        OnlineReturnRefundAllocationDestination::BankTransfer,
                                        OnlineReturnRefundAllocationDestination::Upi,
                                    ], true))
                                        <label class="form-label small mb-0">Payout reference or transaction ID (required to complete)</label>
                                        <input type="text" name="execution_reference" class="form-control form-control-sm" maxlength="128" value="{{ old('execution_reference', $line->execution_reference) }}">
                                        <input type="text" name="transaction_id" class="form-control form-control-sm" maxlength="128" placeholder="Transaction ID (optional)">
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-outline-primary align-self-start">
                                        @if ($line->destination === OnlineReturnRefundAllocationDestination::Wallet)
                                            Execute wallet credit
                                        @elseif ($line->destination === OnlineReturnRefundAllocationDestination::Opm)
                                            Record execution attempt (OPM)
                                        @else
                                            Record manual payout evidence
                                        @endif
                                    </button>
                                </form>
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
