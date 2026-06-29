<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="batch-transaction">
    @csrf
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">
    @foreach($incidentIds as $incidentId)
        <input type="hidden" name="incident_ids[]" value="{{ $incidentId }}">
    @endforeach
    <div class="modal-header">
        <h2 class="modal-title h5" id="batchTransactionModalLabel">Assign Service Reference</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
        <p class="small text-muted mb-3">
            Selected Orders: <strong>{{ $selectedCount }}</strong>
        </p>

        @php
            $serials = $incidents
                ->map(fn ($incident) => $incident->order?->serial_number)
                ->filter(fn ($serial) => filled($serial))
                ->values();
        @endphp

        <div class="batch-serial-section mb-3">
            <div class="batch-serial-section__header">
                <label class="form-label mb-0">Serial Numbers</label>
                @if($serials->isNotEmpty())
                    <button type="button"
                            class="btn btn-sm btn-link batch-serial-section__copy-all p-0"
                            data-copy-all-serials>
                        📋 Copy All Serials
                    </button>
                @endif
            </div>
            @if($serials->isNotEmpty())
                <ul class="batch-serial-list list-unstyled mb-0">
                    @foreach($serials as $serial)
                        <li>
                            <button type="button"
                                    class="batch-serial-item"
                                    data-batch-serial-copy
                                    data-serial="{{ $serial }}">
                                <span class="batch-serial-item__bullet" aria-hidden="true">•</span>
                                <span class="batch-serial-item__value">{{ $serial }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="small text-muted mb-0">No serial numbers available.</p>
            @endif
        </div>

        <div>
            <label for="batch_transaction_id" class="form-label">Service Reference</label>
            <input type="text"
                   name="transaction_id"
                   id="batch_transaction_id"
                   class="form-control @error('transaction_id') is-invalid @enderror"
                   placeholder="Enter service reference"
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
            <i class="bi bi-check-lg me-1"></i> Assign Service Reference
        </button>
    </div>
</form>
