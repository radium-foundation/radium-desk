<form method="POST" action="{{ route('incidents.status.update', $incident) }}">
    @csrf
    @method('PATCH')
    <input type="hidden" name="status" value="resolved">
    <div class="modal-header">
        <h2 class="modal-title h5" id="resolveModalLabel">Resolve Service Case</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="mb-0">Mark this service case as resolved? The case will remain open for follow-up until closed.</p>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-circle me-1"></i> Resolve
        </button>
    </div>
</form>
