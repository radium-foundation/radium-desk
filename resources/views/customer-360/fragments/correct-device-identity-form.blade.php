@php
    $order = $incident->order;
    $deviceModelIdValue = old('device_model_id', $currentDeviceModelId ?? '');
    $serialNumberValue = old('serial_number', $currentSerial ?? '');
    $reasonValue = old('reason');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="correct-device-identity"
      data-correct-device-identity-dialog
      data-c360-dialog
      data-c360-success-title="Device identity corrected"
      data-c360-success-items="Updated Successfully|Audit Recorded|Timeline Updated|Customer360 Refreshed|Protected from Automatic RadiumBox Sync"
      data-original-serial-number="{{ $currentSerial }}"
      data-original-device-model-id="{{ $currentDeviceModelId }}"
      data-original-device-model-name="{{ $currentDeviceModel }}"
      data-correct-device-identity-validation-url="{{ $workspaceValidationUrl }}"
      class="workspace-note-dialog c360-dialog c360-correction-dialog correct-device-identity-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    <input type="hidden" name="confirm_model_switch" value="0" data-correct-device-identity-confirm-switch>

    <x-c360.dialog-header
        icon="📱"
        title="Correct Device Identity"
        subtitle="Update device model and serial number together in one verified correction." />

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
                    :show-device-model-action="false"
                    variant="sidebar" />
            </x-slot:sidebar>

            <div data-correct-device-identity-step="edit" class="c360-dialog-step">
                <section class="c360-identity-panel mb-3">
                    <div class="c360-identity-panel-head">
                        <h3 class="c360-identity-panel-title">Device identity</h3>
                        <p class="c360-identity-panel-copy">Select the verified model and enter the serial from the physical device label.</p>
                    </div>

                    <div class="c360-identity-panel-grid">
                        <div class="c360-dialog-field">
                            <label for="correct-device-identity-model-id" class="form-label">Device Model</label>
                            @include('dashboard.partials.device-model-select', [
                                'selectId' => 'correct-device-identity-model-id',
                                'fieldName' => 'device_model_id',
                                'deviceModels' => $deviceModels,
                                'selectedId' => $deviceModelIdValue,
                                'placeholder' => 'Search device model…',
                                'hasError' => $errors->has('device_model_id'),
                            ])
                            @error('device_model_id')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="c360-dialog-field">
                            <label for="correct-device-identity-serial" class="form-label">Serial Number</label>
                            <input type="text"
                                   id="correct-device-identity-serial"
                                   name="serial_number"
                                   class="form-control font-monospace @error('serial_number') is-invalid @enderror"
                                   value="{{ $serialNumberValue }}"
                                   maxlength="100"
                                   autocomplete="off"
                                   spellcheck="false"
                                   placeholder="Enter serial from device label"
                                   data-c360-change-field
                                   data-correct-device-identity-serial-input
                                   required>
                            @error('serial_number')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="c360-dialog-validation-stack d-none c360-dialog-validation-stack--live mt-2"
                         data-correct-device-identity-live-validation
                         aria-live="polite">
                        <div data-correct-device-identity-live-validation-banner></div>
                        <div data-correct-device-identity-live-duplicate-banner></div>
                        <p class="c360-dialog-serial-normalized mb-0 d-none"
                           data-correct-device-identity-live-normalized></p>
                    </div>

                    <x-c360.change-status class="mt-2 mb-0" unchanged-text="No changes" />
                </section>

                <x-c360.section-card
                    title="Correction reason"
                    heading-id="correct-device-identity-reason-heading"
                    class="mb-2 c360-identity-panel-card">
                    <x-c360.reason-field
                        id="correct-device-identity-reason"
                        name="reason"
                        label="Why is device identity being corrected?"
                        :value="$reasonValue"
                        compact
                        show-counter />
                </x-c360.section-card>

                <x-c360.section-card
                    title="Verification source"
                    heading-id="correct-device-identity-verification-heading"
                    class="mb-0 c360-identity-panel-card">
                    <x-c360.verification-source />
                </x-c360.section-card>
            </div>

            <div class="d-none c360-dialog-step"
                 data-correct-device-identity-step="mismatch"
                 aria-live="polite">
                <section class="c360-identity-mismatch-card">
                    <div class="c360-identity-mismatch-icon" aria-hidden="true">⚠</div>
                    <h3 class="c360-identity-mismatch-title">Serial belongs to a different model</h3>
                    <p class="c360-identity-mismatch-copy">
                        The entered serial belongs to
                        <strong data-correct-device-identity-mismatch-detected>—</strong>
                        instead of
                        <strong data-correct-device-identity-mismatch-selected>—</strong>.
                    </p>
                    <p class="c360-identity-mismatch-hint">Switch the model to match the serial, or keep editing if the serial needs correction.</p>
                </section>
            </div>

            <div class="d-none c360-dialog-step"
                 data-correct-device-identity-step="review"
                 aria-live="polite">
                <x-c360.section-card title="Review" heading-id="correct-device-identity-review-heading">
                    <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                         role="alert"
                         data-correct-device-identity-no-changes>
                        Device identity was not changed.
                    </div>

                    <div class="c360-dialog-review-list"
                         data-correct-device-identity-review-list></div>

                    <section class="c360-dialog-review-card c360-dialog-review-reason-card d-none"
                             data-correct-device-identity-review-reason
                             aria-labelledby="correct-device-identity-review-reason-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-device-identity-review-reason-heading">
                            Reason
                        </h4>
                        <p class="c360-dialog-review-reason-text mb-0"
                           data-correct-device-identity-review-reason-text></p>
                    </section>

                    <section class="c360-dialog-review-card c360-dialog-review-source-card d-none"
                             data-correct-device-identity-review-source
                             aria-labelledby="correct-device-identity-review-source-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-device-identity-review-source-heading">
                            Verification source
                        </h4>
                        <p class="c360-dialog-review-source-text mb-0"
                           data-correct-device-identity-review-source-text></p>
                    </section>

                    <div class="c360-dialog-validation-stack mt-3 d-none"
                         data-correct-device-identity-review-validation>
                        <div data-correct-device-identity-review-validation-banner></div>
                        <div data-correct-device-identity-review-duplicate-banner></div>
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
                data-correct-device-identity-back>
            Back
        </button>
        <button type="button"
                class="btn c360-dialog-btn-ghost d-none"
                data-correct-device-identity-keep-editing>
            Keep Editing
        </button>
        <button type="button"
                class="btn c360-dialog-btn-primary"
                data-correct-device-identity-review
                disabled>
            Review Changes
        </button>
        <button type="button"
                class="btn c360-dialog-btn-primary d-none"
                data-correct-device-identity-switch-model>
            Switch Model &amp; Save
        </button>
        <button type="submit"
                class="btn c360-dialog-btn-primary d-none"
                data-correct-device-identity-confirm>
            Confirm Correction
        </button>
    </x-c360.modal-footer>
</form>
