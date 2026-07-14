@php
    $order = $incident->order;
    $deviceModelIdValue = old('device_model_id', '');
    $reasonValue = old('reason');
@endphp

<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="correct-device-model"
      data-correct-device-model-dialog
      data-c360-dialog
      data-c360-success-title="Device model corrected"
      data-c360-success-items="Updated Successfully|Audit Recorded|Timeline Updated|Customer360 Refreshed|Protected from Automatic RadiumBox Sync"
      data-original-device-model-id="{{ $currentDeviceModelId }}"
      data-original-device-model-name="{{ $currentDeviceModel }}"
      class="workspace-note-dialog c360-dialog c360-correction-dialog correct-device-model-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header
        icon="📱"
        title="Correct Device Model"
        subtitle="Replace the order device model while preserving complete history." />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <x-c360.dialog-body-layout>
            <x-slot:sidebar>
                <x-c360.correction-dialog-sidebar
                    :order="$order"
                    :incident="$incident"
                    :workspace-context="$workspaceContext"
                    :show-serial-action="false"
                    :show-device-model-action="false"
                    :can-correct-device-model="false" />
            </x-slot:sidebar>

            <div data-correct-device-model-step="edit" class="c360-dialog-step">
                <x-c360.section-card
                    title="Device model"
                    heading-id="correct-device-model-fields-heading"
                    class="mb-2">
                    <div class="c360-dialog-form-grid">
                        <div class="c360-dialog-field c360-dialog-field--full">
                            <label for="correct-device-model-id" class="form-label">New Device Model</label>
                            @include('dashboard.partials.device-model-select', [
                                'selectId' => 'correct-device-model-id',
                                'fieldName' => 'device_model_id',
                                'deviceModels' => $deviceModels,
                                'selectedId' => $deviceModelIdValue,
                                'placeholder' => 'Select device model',
                                'hasError' => $errors->has('device_model_id'),
                            ])
                            @error('device_model_id')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <x-c360.change-status class="mt-2 mb-0" unchanged-text="No changes" />
                </x-c360.section-card>

                <x-c360.section-card
                    title="Correction reason"
                    heading-id="correct-device-model-reason-heading"
                    class="mb-2">
                    <x-c360.reason-field
                        id="correct-device-model-reason"
                        name="reason"
                        label="Why is the device model being corrected?"
                        :value="$reasonValue"
                        compact
                        show-counter />
                </x-c360.section-card>

                <x-c360.section-card
                    title="Verification source"
                    heading-id="correct-device-model-verification-heading"
                    class="mb-0">
                    <x-c360.verification-source />
                </x-c360.section-card>
            </div>

            <div class="d-none c360-dialog-step"
                 data-correct-device-model-step="review"
                 aria-live="polite">
                <x-c360.section-card title="Review" heading-id="correct-device-model-review-heading">
                    <div class="alert alert-warning py-2 px-3 small d-none mb-3"
                         role="alert"
                         data-correct-device-model-no-changes>
                        Device model was not changed.
                    </div>

                    <div class="c360-dialog-review-list"
                         data-correct-device-model-review-list></div>

                    <section class="c360-dialog-review-card c360-dialog-review-reason-card d-none"
                             data-correct-device-model-review-reason
                             aria-labelledby="correct-device-model-review-reason-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-device-model-review-reason-heading">
                            Reason
                        </h4>
                        <p class="c360-dialog-review-reason-text mb-0"
                           data-correct-device-model-review-reason-text></p>
                    </section>

                    <section class="c360-dialog-review-card c360-dialog-review-source-card d-none"
                             data-correct-device-model-review-source
                             aria-labelledby="correct-device-model-review-source-heading">
                        <h4 class="c360-dialog-review-card-title"
                            id="correct-device-model-review-source-heading">
                            Verification source
                        </h4>
                        <p class="c360-dialog-review-source-text mb-0"
                           data-correct-device-model-review-source-text></p>
                    </section>
                </x-c360.section-card>
            </div>
        </x-c360.dialog-body-layout>
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="button"
                class="btn c360-dialog-btn-ghost d-none"
                data-correct-device-model-back>
            Back
        </button>
        <button type="button"
                class="btn c360-dialog-btn-primary"
                data-correct-device-model-review
                disabled>
            Review Changes
        </button>
        <button type="submit"
                class="btn c360-dialog-btn-primary d-none"
                data-correct-device-model-confirm>
            Confirm Changes
        </button>
    </x-c360.modal-footer>
</form>
