<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="batch-transaction">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @foreach($incidentIds as $incidentId)
        <input type="hidden" name="incident_ids[]" value="{{ $incidentId }}">
    @endforeach
    <div class="modal-header">
        <h2 class="modal-title h5" id="batchTransactionModalLabel">Assign Transaction ID</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="small text-muted mb-3">
            Apply one transaction ID to <strong>{{ $selectedCount }}</strong>
            selected service {{ $selectedCount === 1 ? 'case' : 'cases' }}.
        </p>

        <div class="mb-3">
            <label class="form-label">Selected Service Cases</label>
            <ul class="list-group list-group-flush border rounded batch-transaction-order-list">
                @foreach($incidents as $incident)
                    <li class="list-group-item py-2 px-3 small d-flex justify-content-between gap-2">
                        <span class="fw-semibold">{{ $incident->reference_no }}</span>
                        <span class="text-muted">{{ $incident->order?->order_id ?: '—' }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <label for="batch_transaction_id" class="form-label">Transaction ID</label>
            <input type="text"
                   name="transaction_id"
                   id="batch_transaction_id"
                   class="form-control @error('transaction_id') is-invalid @enderror"
                   placeholder="Enter transaction ID"
                   maxlength="100"
                   required
                   autofocus>
            @error('transaction_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i> Assign Transaction ID
        </button>
    </div>
</form>
