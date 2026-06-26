<form method="POST"
      action="{{ $workspaceActionUrl ?? route('incidents.status.update', $incident) }}"
      @if($workspaceActionUrl ?? null) data-workspace-action-form="close" @endif>
    @csrf
    @method('PATCH')
    <input type="hidden" name="status" value="closed">
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif
    <div class="modal-header">
        <h2 class="modal-title h5" id="closeModalLabel">Close Service Case</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="mb-0">Close this service case? This indicates the issue is fully complete and no further action is expected.</p>
        @error('status')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
        @error('workspace_context')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-secondary">
            <i class="bi bi-x-circle me-1"></i> Close Service Case
        </button>
    </div>
</form>
