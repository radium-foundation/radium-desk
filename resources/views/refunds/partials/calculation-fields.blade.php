@props(['prefix' => 'calc', 'refund', 'calculation' => null, 'differenceReasons' => []])

@php
    $cancellation = old('cancellation_charges', $refund->cancellation_charges ?? $calculation?->cancellationCharges ?? 0);
    $gst = old('gst_on_cancellation', $refund->gst_on_cancellation ?? $calculation?->gstOnCancellation ?? 0);
    $other = old('other_deduction', $refund->other_deduction ?? $calculation?->otherDeduction ?? 0);
    $refundAmount = old('refund_amount', $refund->refund_amount ?? $refund->amount ?? $calculation?->refundAmount ?? 0);
@endphp

<div class="border rounded p-3 mb-3">
    <h3 class="h6 mb-3">Refund Calculation</h3>
    <dl class="row small mb-3">
        <dt class="col-6 text-muted">Total Paid</dt>
        <dd class="col-6 text-end">₹{{ number_format($calculation?->totalPaidAmount ?? $refund->total_paid_amount ?? 0, 2) }}</dd>
        <dt class="col-6 text-muted">Already Refunded</dt>
        <dd class="col-6 text-end">₹{{ number_format($calculation?->alreadyRefundedAmount ?? $refund->already_refunded_amount ?? 0, 2) }}</dd>
        <dt class="col-6 text-muted">Maximum Refundable</dt>
        <dd class="col-6 text-end fw-semibold">₹{{ number_format($calculation?->maximumRefundable ?? $refund->maximum_refundable ?? 0, 2) }}</dd>
    </dl>

    <div class="mb-2">
        <label for="{{ $prefix }}_cancellation_charges" class="form-label">Cancellation Charges</label>
        <input type="number" step="0.01" min="0" name="cancellation_charges" id="{{ $prefix }}_cancellation_charges"
               class="form-control @error('cancellation_charges') is-invalid @enderror"
               value="{{ $cancellation }}">
        @error('cancellation_charges')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-2">
        <label for="{{ $prefix }}_gst_on_cancellation" class="form-label">GST on Cancellation</label>
        <input type="number" step="0.01" min="0" name="gst_on_cancellation" id="{{ $prefix }}_gst_on_cancellation"
               class="form-control @error('gst_on_cancellation') is-invalid @enderror"
               value="{{ $gst }}">
        @error('gst_on_cancellation')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-2">
        <label for="{{ $prefix }}_other_deduction" class="form-label">Other Deduction</label>
        <input type="number" step="0.01" min="0" name="other_deduction" id="{{ $prefix }}_other_deduction"
               class="form-control @error('other_deduction') is-invalid @enderror"
               value="{{ $other }}">
        @error('other_deduction')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-2">
        <label for="{{ $prefix }}_refund_amount" class="form-label">Refund Amount</label>
        <input type="number" step="0.01" min="0.01" name="refund_amount" id="{{ $prefix }}_refund_amount"
               class="form-control @error('refund_amount') is-invalid @enderror"
               value="{{ $refundAmount }}">
        @error('refund_amount')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-2">
        <label for="{{ $prefix }}_partial_difference_reason" class="form-label">Reason for Difference</label>
        <select name="partial_difference_reason" id="{{ $prefix }}_partial_difference_reason"
                class="form-select @error('partial_difference_reason') is-invalid @enderror">
            <option value="">Not required for full refund</option>
            @foreach($differenceReasons as $reason)
                <option value="{{ $reason->value }}"
                    @selected(old('partial_difference_reason', $refund->partial_difference_reason?->value) === $reason->value)>
                    {{ $reason->label() }}
                </option>
            @endforeach
        </select>
        @error('partial_difference_reason')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="mb-0">
        <label for="{{ $prefix }}_partial_difference_notes" class="form-label">Difference Notes</label>
        <textarea name="partial_difference_notes" id="{{ $prefix }}_partial_difference_notes" rows="2"
                  class="form-control @error('partial_difference_notes') is-invalid @enderror"
                  placeholder="Required when reason is Other">{{ old('partial_difference_notes', $refund->partial_difference_notes) }}</textarea>
        @error('partial_difference_notes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
