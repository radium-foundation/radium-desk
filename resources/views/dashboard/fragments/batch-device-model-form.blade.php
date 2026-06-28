<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="batch-device-model">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @foreach($incidentIds as $incidentId)
        <input type="hidden" name="incident_ids[]" value="{{ $incidentId }}">
    @endforeach
    <div class="modal-header">
        <h2 class="modal-title h5" id="batchDeviceModelModalLabel">Assign Device Model</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="small text-muted mb-3">
            Apply one device model to <strong>{{ $selectedCount }}</strong>
            selected service {{ $selectedCount === 1 ? 'case' : 'cases' }}.
        </p>

        <div class="mb-3">
            <label for="batch_device_model_search" class="form-label">Search</label>
            <input type="search"
                   id="batch_device_model_search"
                   class="form-control form-control-sm"
                   placeholder="Search..."
                   autocomplete="off"
                   data-batch-device-model-search>
        </div>

        <div class="device-model-option-list mb-3"
             role="radiogroup"
             aria-label="Device models"
             data-batch-device-model-options>
            @foreach($deviceModels as $deviceModel)
                <label class="device-model-option d-flex align-items-center gap-2 py-1 mb-0 small"
                       data-device-model-name="{{ strtolower($deviceModel['name']) }}">
                    <input type="radio"
                           name="device_model_id"
                           class="form-check-input mt-0 @error('device_model_id') is-invalid @enderror"
                           value="{{ $deviceModel['id'] }}"
                           @checked(old('device_model_id') == $deviceModel['id'])>
                    <span>{{ $deviceModel['name'] }}</span>
                </label>
            @endforeach
        </div>
        @error('device_model_id')
            <div class="invalid-feedback d-block">{{ $message }}</div>
        @enderror
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Save
        </button>
    </div>
</form>
