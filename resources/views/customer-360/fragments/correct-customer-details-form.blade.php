@php
    $order = $incident->order;
    $customerNameValue = old('customer_name', $customerName);
    $customerPhoneValue = old('customer_phone', $customerPhone);
    $customerEmailValue = old('customer_email', $customerEmail);
    $reasonValue = old('reason');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="correct-customer-details"
      data-correct-customer-details-dialog
      data-c360-dialog
      data-c360-success-title="Customer details updated successfully"
      data-c360-success-items="Audit recorded|History saved|Customer360 refreshed"
      data-original-customer-name="{{ $customerName }}"
      data-original-customer-phone="{{ $customerPhone }}"
      data-original-customer-email="{{ $customerEmail }}"
      class="workspace-note-dialog c360-dialog correct-customer-details-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header
        icon="👤"
        title="Correct Customer Details"
        subtitle="Safely update customer information while preserving complete history." />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <x-c360.identity-summary
            :order="$order"
            :incident="$incident"
            :workspace-context="$workspaceContext"
            :can-correct-serial-number="$canCorrectSerialNumber ?? false" />

        <div data-correct-customer-details-step="edit">
            <x-c360.section-card
                title="Customer Details"
                heading-id="correct-customer-details-fields-heading"
                class="mb-3">
                <div class="c360-dialog-form-grid">
                    <div class="c360-dialog-field">
                        <label for="correct-customer-name" class="form-label">Customer Name</label>
                        <input type="text"
                               id="correct-customer-name"
                               name="customer_name"
                               class="form-control @error('customer_name') is-invalid @enderror"
                               value="{{ $customerNameValue }}"
                               maxlength="255"
                               autocomplete="name"
                               data-c360-change-field>
                        @error('customer_name')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="c360-dialog-field">
                        <label for="correct-customer-phone" class="form-label">Mobile Number</label>
                        <input type="text"
                               id="correct-customer-phone"
                               name="customer_phone"
                               class="form-control @error('customer_phone') is-invalid @enderror"
                               value="{{ $customerPhoneValue }}"
                               maxlength="50"
                               autocomplete="tel"
                               data-c360-change-field>
                        @error('customer_phone')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="c360-dialog-field c360-dialog-field--full">
                        <label for="correct-customer-email" class="form-label">Email Address</label>
                        <input type="email"
                               id="correct-customer-email"
                               name="customer_email"
                               class="form-control @error('customer_email') is-invalid @enderror"
                               value="{{ $customerEmailValue }}"
                               maxlength="255"
                               autocomplete="email"
                               data-c360-change-field>
                        @error('customer_email')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                <x-c360.change-status class="mt-3 mb-0" />
            </x-c360.section-card>

            <x-c360.section-card
                title="Reason"
                heading-id="correct-customer-details-reason-heading">
                <div class="c360-dialog-field mb-0">
                    <label for="correct-customer-reason" class="form-label">
                        Why are these details being corrected? <span class="text-danger">*</span>
                    </label>
                    <textarea id="correct-customer-reason"
                              name="reason"
                              rows="4"
                              class="form-control @error('reason') is-invalid @enderror"
                              maxlength="2000"
                              placeholder="e.g. Customer called from registered number and requested spelling correction."
                              required>{{ $reasonValue }}</textarea>
                    @error('reason')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </x-c360.section-card>
        </div>

        <div class="d-none" data-correct-customer-details-step="review" aria-live="polite">
            <x-c360.section-card title="Review" heading-id="correct-customer-details-review-heading">
                <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                     role="alert"
                     data-correct-customer-details-no-changes>
                    No customer fields were changed.
                </div>

                <div class="c360-dialog-review-list"
                     data-correct-customer-details-review-list></div>

                <section class="c360-dialog-review-reason d-none"
                         data-correct-customer-details-review-reason
                         aria-labelledby="correct-customer-details-review-reason-heading">
                    <h4 class="c360-dialog-review-reason-title"
                        id="correct-customer-details-review-reason-heading">
                        Reason
                    </h4>
                    <p class="c360-dialog-review-reason-text mb-0"
                       data-correct-customer-details-review-reason-text></p>
                </section>
            </x-c360.section-card>

            <x-c360.timeline-preview
                class="mt-3"
                :items="[
                    'Update customer',
                    'Record audit log',
                    'Save correction history',
                    'Refresh Customer360',
                ]" />
        </div>
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button"
                class="btn btn-outline-secondary d-none"
                data-correct-customer-details-back>
            Back
        </button>
        <button type="button"
                class="btn btn-outline-primary"
                data-correct-customer-details-review
                disabled>
            Review Changes
        </button>
        <button type="submit"
                class="btn btn-primary d-none"
                data-correct-customer-details-confirm>
            Confirm Changes
        </button>
    </x-c360.modal-footer>
</form>
