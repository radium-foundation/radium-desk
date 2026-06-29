<form method="POST"
      action="{{ $workspaceActionUrl ?? route('incidents.status.update', $incident) }}"
      @if($workspaceActionUrl ?? null) data-workspace-action-form="{{ $action }}" @endif>
    @csrf
    @method('PATCH')
    <input type="hidden" name="status" value="{{ $statusValue }}">
    @if($workspaceContext ?? null)
        <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @endif
    <div class="modal-header">
        <h2 class="modal-title h5" id="{{ $modalLabelId }}">{{ $title }}</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        @include('service-cases.fragments.partials.action-remark-fields', [
            'action' => $action,
            'incident' => $incident,
            'description' => $description ?? null,
            'mentionUsers' => $mentionUsers ?? [],
            'remarkBody' => $remarkBody ?? null,
        ])
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
        <button type="submit" class="btn {{ $submitBtnClass }}">
            <i class="bi {{ $submitIcon }} me-1"></i> {{ $submitLabel }}
        </button>
    </div>
</form>
