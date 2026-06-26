<form method="POST"
      action="{{ $workspaceActionUrl ?? route('incidents.status.update', $incident) }}"
      @if($workspaceActionUrl ?? null) data-workspace-action-form="resolve" @endif>
    @csrf
    @method('PATCH')
    <input type="hidden" name="status" value="resolved">
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif
    <div class="modal-header">
        <h2 class="modal-title h5" id="resolveModalLabel">Resolve Service Case</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="mb-0">Mark this service case as resolved? The case will remain open for follow-up until closed.</p>
        @error('status')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
        @error('remarks')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
        @error('transaction_id')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
        @error('workspace_context')
            <div class="text-danger small mt-2">{{ $message }}</div>
        @enderror
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i> Resolve
        </button>
    </div>
</form>
