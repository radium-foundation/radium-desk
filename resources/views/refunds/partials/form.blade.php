@props(['refund', 'selectedOrder' => null, 'selectedIncident' => null])

<div class="row g-3">
    @include('refunds.partials.order-incident-select', [
        'selectedOrder' => $selectedOrder,
        'selectedIncident' => $selectedIncident,
    ])

    <div class="col-md-6">
        <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
        <div class="input-group">
            <span class="input-group-text">₹</span>
            <input type="number" name="amount" id="amount" step="0.01" min="0.01"
                   class="form-control @error('amount') is-invalid @enderror"
                   value="{{ old('amount', $refund->amount) }}" required>
        </div>
        @error('amount')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-12">
        <label for="reason" class="form-label">Reason <span class="text-danger">*</span></label>
        <textarea name="reason" id="reason" rows="5"
                  class="form-control @error('reason') is-invalid @enderror"
                  placeholder="Describe why this refund is being requested..." required>{{ old('reason', $refund->reason) }}</textarea>
        @error('reason')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>
