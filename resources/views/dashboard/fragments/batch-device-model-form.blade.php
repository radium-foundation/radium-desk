<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="batch-device-model">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @foreach($incidentIds as $incidentId)
        <input type="hidden" name="incident_ids[]" value="{{ $incidentId }}">
    @endforeach
    <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="batchDeviceModelModalLabel">Assign Model to Selected Requests</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body py-2">
        @include('dashboard.partials.device-model-select', [
            'deviceModels' => $deviceModels,
            'selectId' => 'batch_device_model_select',
        ])
    </div>
    <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </div>
</form>
