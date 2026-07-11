@php
    use App\Enums\SerialValidationSeverity;

    $order = $incident->order;
    $serialNumberValue = old('serial_number', '');
    $reasonValue = old('reason');
    $currentValidation = $currentValidation ?? null;
    $currentDuplicateOrderId = $currentDuplicateOrderId ?? null;
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="correct-serial-number"
      data-correct-serial-number-dialog
      data-c360-dialog
      data-c360-success-title="Serial number corrected"
      data-c360-success-items="Updated Successfully|Audit Recorded|Timeline Updated|Customer360 Refreshed|Protected from Automatic RadiumBox Sync"
      data-original-serial-number="{{ $currentSerial }}"
      data-correct-serial-validation-url="{{ $workspaceValidationUrl }}"
      class="workspace-note-dialog c360-dialog correct-serial-number-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header
        icon="🔢"
        title="Correct Serial Number"
        subtitle="Replace the order serial with a validated value while preserving complete history." />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <x-c360.dialog-body-layout>
            <x-slot:sidebar>
                <x-c360.identity-summary
                    :order="$order"
                    :incident="$incident"
                    :workspace-context="$workspaceContext"
                    :show-serial-action="false"
                    variant="sidebar" />
            </x-slot:sidebar>

            <div data-correct-serial-number-step="edit" class="c360-dialog-step">
                @if($currentValidation !== null || filled($currentDuplicateOrderId))
                    <x-c360.section-card
                        title="Current Serial Status"
                        heading-id="correct-serial-current-status-heading"
                        class="mb-3">
                        <div class="c360-dialog-validation-stack" data-correct-serial-current-status>
                            @if($currentValidation !== null)
                                @php
                                    $validationType = match ($currentValidation->severity) {
                                        SerialValidationSeverity::Pass => 'pass',
                                        SerialValidationSeverity::Warning => 'warning',
                                        default => 'fail',
                                    };
                                    $validationMessage = match ($currentValidation->severity) {
                                        SerialValidationSeverity::Pass => 'Serial format valid',
                                        SerialValidationSeverity::Warning => 'Validation warning',
                                        default => 'Invalid serial',
                                    };
                                @endphp
                                <x-c360.validation-banner
                                    :type="$validationType"
                                    :message="$validationMessage"
                                    :detail="filled($currentValidation->reason) ? $currentValidation->reason : null"
                                    data-correct-serial-current-validation />
                            @endif

                            @if(filled($currentDuplicateOrderId))
                                <x-c360.validation-banner
                                    type="duplicate-conflict"
                                    message="Already assigned"
                                    :detail="'Used by order '.$currentDuplicateOrderId"
                                    data-correct-serial-current-duplicate />
                            @else
                                <x-c360.validation-banner
                                    type="duplicate-clear"
                                    message="Available"
                                    detail="No duplicate detected"
                                    data-correct-serial-current-duplicate />
                            @endif
                        </div>
                    </x-c360.section-card>
                @endif

                <x-c360.section-card
                    title="Corrected Serial"
                    heading-id="correct-serial-number-fields-heading"
                    class="mb-3">
                    <div class="c360-dialog-field mb-0">
                        <label for="correct-serial-number" class="form-label">New Serial Number</label>
                        <input type="text"
                               id="correct-serial-number"
                               name="serial_number"
                               class="form-control font-monospace @error('serial_number') is-invalid @enderror"
                               value="{{ $serialNumberValue }}"
                               maxlength="100"
                               autocomplete="off"
                               spellcheck="false"
                               data-c360-change-field
                               data-correct-serial-number-input
                               required>
                        @error('serial_number')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror

                        <div class="c360-dialog-validation-stack d-none c360-dialog-validation-stack--live"
                             data-correct-serial-live-validation
                             aria-live="polite">
                            <div data-correct-serial-live-validation-banner></div>
                            <div data-correct-serial-live-duplicate-banner></div>
                            <p class="c360-dialog-serial-normalized mb-0 d-none"
                               data-correct-serial-live-normalized></p>
                        </div>
                    </div>

                    <x-c360.change-status class="mt-3 mb-0" />
                </x-c360.section-card>

                <x-c360.section-card
                    title="Reason"
                    heading-id="correct-serial-number-reason-heading"
                    class="mb-3">
                    <x-c360.reason-field
                        id="correct-serial-reason"
                        name="reason"
                        label="Why is this serial being corrected?"
                        :value="$reasonValue" />
                </x-c360.section-card>

                <x-c360.section-card
                    title="Verification"
                    heading-id="correct-serial-verification-heading"
                    class="mb-0">
                    <x-c360.verification-source />
                </x-c360.section-card>
            </div>

            <div class="d-none c360-dialog-step"
                 data-correct-serial-number-step="review"
                 aria-live="polite">
                <x-c360.section-card title="Review" heading-id="correct-serial-number-review-heading">
                    <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                         role="alert"
                         data-correct-serial-number-no-changes>
                        Serial number was not changed.
                    </div>

                    <div class="c360-dialog-review-list"
                         data-correct-serial-number-review-list></div>

                    <section class="c360-dialog-review-card c360-dialog-review-reason-card d-none"
                             data-correct-serial-number-review-reason
                             aria-labelledby="correct-serial-number-review-reason-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-serial-number-review-reason-heading">
                            Reason
                        </h4>
                        <p class="c360-dialog-review-reason-text mb-0"
                           data-correct-serial-number-review-reason-text></p>
                    </section>

                    <section class="c360-dialog-review-card c360-dialog-review-source-card d-none"
                             data-correct-serial-number-review-source
                             aria-labelledby="correct-serial-number-review-source-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-serial-number-review-source-heading">
                            Verification Source
                        </h4>
                        <p class="c360-dialog-review-source-text mb-0"
                           data-correct-serial-number-review-source-text></p>
                    </section>

                    <div class="c360-dialog-validation-stack mt-3 d-none"
                         data-correct-serial-review-validation>
                        <div data-correct-serial-review-validation-banner></div>
                        <div data-correct-serial-review-duplicate-banner></div>
                    </div>
                </x-c360.section-card>

                <x-c360.impact-checklist
                    class="mt-3"
                    :items="[
                        'Customer360',
                        'Search',
                        'Timeline',
                        'Audit',
                        'Future WhatsApp',
                        'Future Email',
                    ]" />
            </div>
        </x-c360.dialog-body-layout>
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="button"
                class="btn c360-dialog-btn-ghost d-none"
                data-correct-serial-number-back>
            Back
        </button>
        <button type="button"
                class="btn c360-dialog-btn-primary"
                data-correct-serial-number-review
                disabled>
            Review Changes
        </button>
        <button type="submit"
                class="btn c360-dialog-btn-primary d-none"
                data-correct-serial-number-confirm>
            Confirm Correction
        </button>
    </x-c360.modal-footer>
</form>
