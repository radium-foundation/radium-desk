<form method="POST"
      action="{{ $workspaceActionUrl ?? route('incidents.assignment.update', $incident) }}"
      @if($workspaceActionUrl ?? null) data-workspace-action-form="assign" @endif>
    @csrf
    @method('PATCH')
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif
    <div class="modal-header">
        <h2 class="modal-title h5" id="assignModalLabel">Assign Service Case</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <label for="modal_assigned_to_user_id" class="form-label">Assigned To</label>
        <select name="assigned_to_user_id"
                id="modal_assigned_to_user_id"
                class="form-select @error('assigned_to_user_id') is-invalid @enderror"
                required>
            <option value="" disabled @selected(old('assigned_to_user_id') === null && $incident->assigned_to_user_id === null)>Select admin</option>
            @foreach($reassignableAdmins as $adminUser)
                <option value="{{ $adminUser->id }}"
                    @selected((int) old('assigned_to_user_id', $incident->assigned_to_user_id) === $adminUser->id)>
                    {{ $adminUser->firstName() }}
                </option>
            @endforeach
        </select>
        @error('assigned_to_user_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-person-check me-1"></i> Assign
        </button>
    </div>
</form>
