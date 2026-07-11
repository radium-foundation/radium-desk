<form method="POST"
      action="{{ $workspaceActionUrl }}"
      data-workspace-action-form="link-order"
      data-c360-dialog
      data-c360-success-title="Order linked successfully"
      class="workspace-note-dialog c360-dialog link-order-dialog">
    @csrf
    @method('PATCH')
    <input type="hidden" name="workspace_context" value="{{ $workspaceContext }}">

    <x-c360.dialog-header
        icon="🔗"
        title="Link Order"
        subtitle="Connect this enquiry to the customer's real order. The service case reference stays the same." />

    <div class="modal-body workspace-note-dialog-body c360-dialog-body pt-2">
        <x-c360.section-card title="Order Details" heading-id="link-order-details-heading">
            <div class="c360-dialog-field mb-3">
                <label for="link-order-id" class="form-label">Order ID</label>
                <input type="text"
                       id="link-order-id"
                       name="order_id"
                       class="form-control font-monospace @error('order_id') is-invalid @enderror"
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
                <label class="form-check-label" for="link-order-confirmed">
                    I confirm this enquiry should continue on the selected order without creating a new service case.
                </label>
                @error('confirmed')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
        </x-c360.section-card>
    </div>

    <x-c360.modal-footer>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Link Order</button>
    </x-c360.modal-footer>
</form>
