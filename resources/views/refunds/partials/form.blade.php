@props([
    'refund',
    'selectedOrder' => null,
    'selectedIncident' => null,
    'calculation' => null,
    'preferredMethods' => [],
    'profiles' => [],
    'differenceReasons' => [],
])

@php
    use App\Enums\RefundDifferenceReason;

    $maximumRefundable = $calculation?->maximumRefundable;
    $amountValue = old('amount', $refund->amount ?? $calculation?->maximumRefundable);
    $partialDifferenceReasonValue = old(
        'partial_difference_reason',
        $refund->partial_difference_reason?->value ?? '',
    );
    $partialDifferenceNotesValue = old(
        'partial_difference_notes',
        $refund->partial_difference_notes ?? '',
    );
    $isPartialRefund = $amountValue !== null
        && $maximumRefundable !== null
        && (float) $amountValue < ((float) $maximumRefundable - 0.001);
    $showPartialNotes = $isPartialRefund
        && (string) $partialDifferenceReasonValue === RefundDifferenceReason::Other->value;
    $differenceReasons = $differenceReasons !== []
        ? $differenceReasons
        : RefundDifferenceReason::cases();
@endphp

<div class="row g-3" data-refund-request-form>
    @include('refunds.partials.order-incident-select', [
        'selectedOrder' => $selectedOrder,
        'selectedIncident' => $selectedIncident,
    ])

    <div class="col-12">
        <div class="border rounded p-3 bg-light-subtle">
            <h3 class="h6 mb-3">Refund Summary</h3>
            <dl class="row mb-0 small">
                <dt class="col-6 text-muted">Total Paid</dt>
                <dd class="col-6 text-end" id="summary_total_paid">₹{{ number_format($calculation?->totalPaidAmount ?? 0, 2) }}</dd>
                <dt class="col-6 text-muted">Already Refunded</dt>
                <dd class="col-6 text-end" id="summary_already_refunded">₹{{ number_format($calculation?->alreadyRefundedAmount ?? 0, 2) }}</dd>
                <dt class="col-6 text-muted">Maximum Refundable</dt>
                <dd class="col-6 text-end fw-semibold" id="summary_maximum_refundable"
                    data-refund-maximum-display="{{ $maximumRefundable !== null ? number_format((float) $maximumRefundable, 2, '.', '') : '' }}">
                    ₹{{ number_format($calculation?->maximumRefundable ?? 0, 2) }}
                </dd>
            </dl>
        </div>
    </div>

    <div class="col-md-6">
        <label for="customer_preferred_method" class="form-label">Customer Preferred Method <span class="text-danger">*</span></label>
        <select name="customer_preferred_method" id="customer_preferred_method"
                class="form-select @error('customer_preferred_method') is-invalid @enderror" required>
            @foreach($preferredMethods as $method)
                <option value="{{ $method->value }}"
                    @selected(old('customer_preferred_method', $refund->customer_preferred_method?->value ?? \App\Enums\CustomerPreferredRefundMethod::Opm->value) === $method->value)>
                    {{ $method->label() }}
                </option>
            @endforeach
        </select>
        @error('customer_preferred_method')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
        <div class="form-text">Advisory only — Ops chooses the actual payout method at approval.</div>
    </div>

    <div class="col-md-6">
        <label for="amount" class="form-label">Requested Amount</label>
        <div class="input-group">
            <span class="input-group-text">₹</span>
            <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                   data-refund-amount-input
                   data-refund-maximum="{{ $maximumRefundable !== null ? number_format((float) $maximumRefundable, 2, '.', '') : '' }}"
                   class="form-control @error('amount') is-invalid @enderror"
                   value="{{ $amountValue }}"
                   placeholder="Defaults to maximum refundable">
        </div>
        @error('amount')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
        @error('refund_amount')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 {{ $isPartialRefund ? '' : 'd-none' }}" data-refund-partial-fields>
        <label for="partial_difference_reason" class="form-label">
            Reason for Partial Refund <span class="text-danger">*</span>
        </label>
        <select name="partial_difference_reason" id="partial_difference_reason"
                data-refund-partial-reason
                class="form-select @error('partial_difference_reason') is-invalid @enderror"
                @disabled(! $isPartialRefund)>
            <option value="">Select a reason</option>
            @foreach($differenceReasons as $differenceReason)
                <option value="{{ $differenceReason->value }}"
                    @selected((string) $partialDifferenceReasonValue === $differenceReason->value)>
                    {{ $differenceReason->label() }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Required only when refund amount is less than the maximum refundable.</div>
        @error('partial_difference_reason')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12 {{ $showPartialNotes ? '' : 'd-none' }}" data-refund-partial-notes-fields>
        <label for="partial_difference_notes" class="form-label">Partial Refund Notes</label>
        <textarea name="partial_difference_notes" id="partial_difference_notes" rows="2"
                  data-refund-partial-notes
                  class="form-control @error('partial_difference_notes') is-invalid @enderror"
                  placeholder="Required when reason is Other"
                  @disabled(! $showPartialNotes)>{{ $partialDifferenceNotesValue }}</textarea>
        @error('partial_difference_notes')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="reason" class="form-label">Refund Request Reason <span class="text-danger">*</span></label>
        <textarea name="reason" id="reason" rows="4"
                  class="form-control @error('reason') is-invalid @enderror"
                  placeholder="Why is the customer requesting a refund?" required>{{ old('reason', $refund->reason) }}</textarea>
        @error('reason')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="remarks" class="form-label">Remarks <span class="text-danger">*</span></label>
        <textarea name="remarks" id="remarks" rows="3"
                  class="form-control @error('remarks') is-invalid @enderror"
                  placeholder="Add remarks for finance review" required>{{ old('remarks', $refund->requester_remarks) }}</textarea>
        @error('remarks')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="refund_attachment" class="form-label">
            Attachment <span class="text-muted fw-normal">(coming soon)</span>
        </label>
        <input type="file" id="refund_attachment" class="form-control" disabled aria-disabled="true">
    </div>

    <div class="col-12">
        <label class="form-label d-block">Customer Communication</label>
        <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notify_email" id="notify_email" value="1"
                       @checked(old('notify_email', false))>
                <label class="form-check-label" for="notify_email">Email</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notify_whatsapp" id="notify_whatsapp" value="1"
                       @checked(old('notify_whatsapp', false))>
                <label class="form-check-label" for="notify_whatsapp">WhatsApp</label>
            </div>
        </div>
        <div class="form-text">Sent automatically after the refund is marked completed.</div>
    </div>
</div>
