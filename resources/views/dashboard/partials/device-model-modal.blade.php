<div class="modal fade"
     id="deviceModelAssignModal"
     tabindex="-1"
     aria-labelledby="deviceModelAssignModalLabel"
     aria-hidden="true"
     data-batch-url="{{ route('dashboard.workspace.batch-device-model') }}">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0"
                    id="deviceModelAssignModalLabel"
                    data-device-model-modal-title>Assign Model</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2">
                <div class="mb-0">
                    @include('dashboard.partials.device-model-select', [
                        'deviceModels' => $activeDeviceModels,
                        'selectId' => 'device_model_select',
                    ])
                    <div class="invalid-feedback" data-device-model-error></div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" data-device-model-save>Save</button>
            </div>
        </div>
    </div>
</div>
