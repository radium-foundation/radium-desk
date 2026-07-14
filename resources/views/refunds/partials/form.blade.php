@props([
    'refund',
    'selectedOrder' => null,
    'selectedIncident' => null,
    'calculation' => null,
    'preferredMethods' => [],
    'profiles' => [],
])

<div class="row g-3">
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
                <dd class="col-6 text-end fw-semibold" id="summary_maximum_refundable">₹{{ number_format($calculation?->maximumRefundable ?? 0, 2) }}</dd>
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
                   class="form-control @error('amount') is-invalid @enderror"
                   value="{{ old('amount', $refund->amount ?? $calculation?->maximumRefundable) }}"
                   placeholder="Defaults to maximum refundable">
        </div>
        @error('amount')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="reason" class="form-label">Refund Reason <span class="text-danger">*</span></label>
        <textarea name="reason" id="reason" rows="4"
                  class="form-control @error('reason') is-invalid @enderror"
                  placeholder="Describe why this refund is being requested..." required>{{ old('reason', $refund->reason) }}</textarea>
        @error('reason')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label class="form-label d-block">Customer Communication</label>
        <div class="d-flex flex-wrap gap-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notify_email" id="notify_email" value="1"
                       @checked(old('notify_email', true))>
                <label class="form-check-label" for="notify_email">Email</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="notify_whatsapp" id="notify_whatsapp" value="1"
                       @checked(old('notify_whatsapp', true))>
                <label class="form-check-label" for="notify_whatsapp">WhatsApp</label>
            </div>
        </div>
        <div class="form-text">Sent automatically after the refund is marked completed.</div>
    </div>
</div>
