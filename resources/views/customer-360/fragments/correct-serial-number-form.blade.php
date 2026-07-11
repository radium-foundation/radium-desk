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
      data-c360-success-title="Serial number corrected successfully"
      data-c360-success-items="Audit recorded|Validation updated|Customer360 refreshed"
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

        <x-c360.identity-summary
            :order="$order"
            :incident="$incident"
            :workspace-context="$workspaceContext"
            :show-serial-action="false" />

        @if($currentValidation !== null || filled($currentDuplicateOrderId))
            <x-c360.section-card
                title="Current Serial Status"
                heading-id="correct-serial-current-status-heading"
                class="mb-3">
                <div class="c360-dialog-serial-status">
                    @if($currentValidation !== null)
                        <div class="c360-dialog-serial-status-row">
                            <span class="c360-dialog-serial-status-label">Validation</span>
                            <span class="c360-dialog-serial-validation-badge c360-dialog-serial-validation-badge--{{ $currentValidation->severity->value }}"
                                  data-correct-serial-current-validation>
                                @if($currentValidation->severity === SerialValidationSeverity::Pass)
                                    ✓ Verified
                                @elseif($currentValidation->severity === SerialValidationSeverity::Warning)
                                    ⚠ Needs review
                                @else
                                    ✕ Validation failed
                                @endif
                            </span>
                        </div>
                        @if(filled($currentValidation->reason))
                            <p class="c360-dialog-serial-status-reason mb-0">{{ $currentValidation->reason }}</p>
                        @endif
                    @endif

                    @if(filled($currentDuplicateOrderId))
                        <div class="c360-dialog-serial-status-row mt-2">
                            <span class="c360-dialog-serial-status-label">Duplicate</span>
                            <span class="c360-dialog-serial-duplicate-badge">
                                Used by order {{ $currentDuplicateOrderId }}
                            </span>
                        </div>
                    @else
                        <div class="c360-dialog-serial-status-row mt-2">
                            <span class="c360-dialog-serial-status-label">Duplicate</span>
                            <span class="c360-dialog-serial-duplicate-badge c360-dialog-serial-duplicate-badge--clear">
                                No duplicate detected
                            </span>
                        </div>
                    @endif
                </div>
            </x-c360.section-card>
        @endif

        <div data-correct-serial-number-step="edit">
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

                    <div class="c360-dialog-serial-live-validation d-none"
                         data-correct-serial-live-validation
                         aria-live="polite">
                        <div class="c360-dialog-serial-status-row">
                            <span class="c360-dialog-serial-status-label">Validation</span>
                            <span class="c360-dialog-serial-validation-badge"
                                  data-correct-serial-live-validation-badge></span>
                        </div>
                        <p class="c360-dialog-serial-status-reason mb-0 d-none"
                           data-correct-serial-live-validation-reason></p>
                        <div class="c360-dialog-serial-status-row mt-2">
                            <span class="c360-dialog-serial-status-label">Duplicate</span>
                            <span class="c360-dialog-serial-duplicate-badge"
                                  data-correct-serial-live-duplicate-badge></span>
                        </div>
                        <p class="c360-dialog-serial-normalized mb-0 d-none"
                           data-correct-serial-live-normalized></p>
                    </div>
                </div>

                <x-c360.change-status class="mt-3 mb-0" />
            </x-c360.section-card>

            <x-c360.section-card
                title="Reason"
                heading-id="correct-serial-number-reason-heading">
                <div class="c360-dialog-field mb-0">
                    <label for="correct-serial-reason" class="form-label">
                        Why is this serial being corrected? <span class="text-danger">*</span>
                    </label>
                    <textarea id="correct-serial-reason"
                              name="reason"
                              rows="4"
                              class="form-control @error('reason') is-invalid @enderror"
                              maxlength="2000"
                              placeholder="e.g. Customer confirmed the correct serial over a verified call."
                              required>{{ $reasonValue }}</textarea>
                    @error('reason')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </x-c360.section-card>
        </div>

        <div class="d-none" data-correct-serial-number-step="review" aria-live="polite">
            <x-c360.section-card title="Review" heading-id="correct-serial-number-review-heading">
                <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                     role="alert"
                     data-correct-serial-number-no-changes>
                    Serial number was not changed.
                </div>

                <div class="c360-dialog-review-list"
                     data-correct-serial-number-review-list></div>

                <section class="c360-dialog-review-reason d-none"
                         data-correct-serial-number-review-reason
                         aria-labelledby="correct-serial-number-review-reason-heading">
                    <h4 class="c360-dialog-review-reason-title"
                        id="correct-serial-number-review-reason-heading">
                        Reason
                    </h4>
                    <p class="c360-dialog-review-reason-text mb-0"
                       data-correct-serial-number-review-reason-text></p>
                </section>

                <div class="c360-dialog-serial-review-validation mt-3 d-none"
                     data-correct-serial-review-validation>
                    <div class="c360-dialog-serial-status-row">
                        <span class="c360-dialog-serial-status-label">Validation</span>
                        <span class="c360-dialog-serial-validation-badge"
                              data-correct-serial-review-validation-badge></span>
                    </div>
                    <p class="c360-dialog-serial-status-reason mb-0"
                       data-correct-serial-review-validation-reason></p>
                    <div class="c360-dialog-serial-status-row mt-2">
                        <span class="c360-dialog-serial-status-label">Duplicate</span>
                        <span class="c360-dialog-serial-duplicate-badge"
                              data-correct-serial-review-duplicate-badge></span>
                    </div>
                </div>
            </x-c360.section-card>

            <x-c360.timeline-preview
                class="mt-3"
                :items="[
                    'Validate serial number',
                    'Update order serial',
                    'Record audit log',
                    'Refresh Customer360',
                ]" />
        </div>
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button"
                class="btn btn-outline-secondary d-none"
                data-correct-serial-number-back>
            Back
        </button>
        <button type="button"
                class="btn btn-outline-primary"
                data-correct-serial-number-review
                disabled>
            Review Changes
        </button>
        <button type="submit"
                class="btn btn-primary d-none"
                data-correct-serial-number-confirm>
            Confirm Correction
        </button>
    </x-c360.modal-footer>
</form>
