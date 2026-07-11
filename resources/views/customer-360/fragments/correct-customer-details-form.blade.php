@php
    $customerNameValue = old('customer_name', $customerName);
    $customerPhoneValue = old('customer_phone', $customerPhone);
    $customerEmailValue = old('customer_email', $customerEmail);
    $reasonValue = old('reason');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="correct-customer-details"
      data-correct-customer-details-dialog
      data-original-customer-name="{{ $customerName }}"
      data-original-customer-phone="{{ $customerPhone }}"
      data-original-customer-email="{{ $customerEmail }}"
      class="workspace-note-dialog correct-customer-details-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">Correct Customer Details</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body pt-3">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <div data-correct-customer-details-step="edit">
            <p class="small text-muted mb-3">
                Update customer contact details for order <strong>{{ $incident->order?->order_id }}</strong>.
            </p>

            <div class="mb-3">
                <label for="correct-customer-name" class="form-label">Customer Name</label>
                <input type="text"
                       id="correct-customer-name"
                       name="customer_name"
                       class="form-control @error('customer_name') is-invalid @enderror"
                       value="{{ $customerNameValue }}"
                       maxlength="255"
                       autocomplete="name">
                @error('customer_name')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="correct-customer-phone" class="form-label">Customer Phone</label>
                <input type="text"
                       id="correct-customer-phone"
                       name="customer_phone"
                       class="form-control @error('customer_phone') is-invalid @enderror"
                       value="{{ $customerPhoneValue }}"
                       maxlength="50"
                       autocomplete="tel">
                @error('customer_phone')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="correct-customer-email" class="form-label">Customer Email</label>
                <input type="email"
                       id="correct-customer-email"
                       name="customer_email"
                       class="form-control @error('customer_email') is-invalid @enderror"
                       value="{{ $customerEmailValue }}"
                       maxlength="255"
                       autocomplete="email">
                @error('customer_email')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-0">
                <label for="correct-customer-reason" class="form-label">Reason <span class="text-danger">*</span></label>
                <textarea id="correct-customer-reason"
                          name="reason"
                          rows="3"
                          class="form-control @error('reason') is-invalid @enderror"
                          maxlength="2000"
                          required>{{ $reasonValue }}</textarea>
                @error('reason')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="d-none" data-correct-customer-details-step="review" aria-live="polite">
            <p class="small text-muted mb-3">
                Review the customer detail changes below before confirming.
            </p>

            <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                 role="alert"
                 data-correct-customer-details-no-changes>
                No customer fields were changed.
            </div>

            <div class="correct-customer-details-review-list"
                 data-correct-customer-details-review-list></div>
        </div>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button"
                class="btn btn-outline-secondary d-none"
                data-correct-customer-details-back>
            Back
        </button>
        <button type="button"
                class="btn btn-primary"
                data-correct-customer-details-review>
            Review
        </button>
        <button type="submit"
                class="btn btn-primary d-none"
                data-correct-customer-details-confirm>
            Confirm
        </button>
    </div>
</form>
