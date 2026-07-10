<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="link-order"
      class="workspace-note-dialog link-order-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <div class="modal-header border-0 pb-0">
        <h2 class="modal-title h5 mb-0">Link Order</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    <div class="modal-body pt-3">
        <p class="small text-muted mb-3">
            Connect enquiry <strong>{{ $incident->display_reference }}</strong> to the customer's real order.
            The service case reference stays the same.
        </p>

        <div class="mb-3">
            <label for="link-order-id" class="form-label">Order ID</label>
            <input type="text"
                   id="link-order-id"
                   name="order_id"
                   class="form-control font-monospace"
                   placeholder="RD3446000"
                   value="{{ old('order_id') }}"
                   required
                   autocomplete="off">
            @error('order_id')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="form-check mb-0">
            <input class="form-check-input"
                   type="checkbox"
                   name="confirmed"
                   id="link-order-confirmed"
                   value="1"
                   required>
            <label class="form-check-label small" for="link-order-confirmed">
                I confirm this enquiry should continue on the selected order without creating a new service case.
            </label>
            @error('confirmed')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>
    </div>

    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Link Order</button>
    </div>
</form>
