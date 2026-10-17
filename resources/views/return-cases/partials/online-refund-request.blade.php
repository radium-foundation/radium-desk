@php
    use App\Enums\ReturnExchangeCaseSourceKind;

    $isOnline = in_array($case->source_kind, [
        ReturnExchangeCaseSourceKind::EcommerceOrder,
        ReturnExchangeCaseSourceKind::EcommerceFulfilment,
    ], true);
@endphp

@if ($isOnline)
    <div class="card mb-3 border-primary">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">Online hardware refund (Desk RefundRequest)</h2>
        </div>
        <div class="card-body small">
            <p class="text-muted mb-2">
                Creates a standard order refund for Finance review and execution. No customer notifications from this screen.
            </p>

            @if ($case->refundRequest)
                <dl class="row mb-0">
                    <dt class="col-5 col-sm-3">Refund reference</dt>
                    <dd class="col-7 col-sm-9">
                        <a href="{{ route('refunds.show', $case->refundRequest) }}">{{ $case->refundRequest->reference_no }}</a>
                    </dd>
                    <dt class="col-5 col-sm-3">Status</dt>
                    <dd class="col-7 col-sm-9">{{ $case->refundRequest->status->label() }}</dd>
                    @if ($case->recorded_tax_outcome_type)
                        <dt class="col-5 col-sm-3">Tax outcome used</dt>
                        <dd class="col-7 col-sm-9">{{ $case->recorded_tax_outcome_type }}</dd>
                    @endif
                </dl>
            @elseif ($canSubmitOnlineRefund ?? false)
                <form method="post" action="{{ route('return-cases.online-refund-request.store', $case) }}" class="vstack gap-2">
                    @csrf
                    <p class="mb-1">Submit after inspection and recorded cancellation or credit note on the linked invoice.</p>
                    <label class="form-label" for="online-refund-reason">Reason (optional)</label>
                    <input id="online-refund-reason" name="reason" class="form-control form-control-sm" maxlength="2000" value="{{ old('reason') }}">
                    <label class="form-label" for="online-refund-remarks">Internal remarks (optional)</label>
                    <textarea id="online-refund-remarks" name="requester_remarks" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('requester_remarks') }}</textarea>
                    <button type="submit" class="btn btn-sm btn-primary align-self-start">Submit linked refund request</button>
                </form>
            @else
                <p class="mb-0 text-muted">No linked refund request yet.</p>
            @endif
        </div>
    </div>
@endif
