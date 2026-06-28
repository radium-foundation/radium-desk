<div class="modal fade"
     id="deviceModelAssignModal"
     tabindex="-1"
     aria-labelledby="deviceModelAssignModalLabel"
     aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="deviceModelAssignModalLabel">Assign Device Model</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2">
                <div class="mb-2">
                    <label for="device_model_search" class="visually-hidden">Search device models</label>
                    <input type="search"
                           id="device_model_search"
                           class="form-control form-control-sm"
                           placeholder="Search..."
                           autocomplete="off"
                           data-device-model-search>
                </div>
                <div class="device-model-option-list"
                     role="radiogroup"
                     aria-label="Device models"
                     data-device-model-options>
                    @foreach($activeDeviceModels as $deviceModel)
                        <label class="device-model-option d-flex align-items-center gap-2 py-1 mb-0 small"
                               data-device-model-name="{{ strtolower($deviceModel['name']) }}">
                            <input type="radio"
                                   name="device_model_choice"
                                   class="form-check-input mt-0"
                                   value="{{ $deviceModel['id'] }}"
                                   data-device-model-radio>
                            <span>{{ $deviceModel['name'] }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="invalid-feedback d-block small" data-device-model-error></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" data-device-model-save>Save</button>
            </div>
        </div>
    </div>
</div>
