<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.invoice.store', $fulfilment) }}"
      data-hardware-action-form
      id="hardware-action-invoice-form">
    @csrf
    <x-c360.section-card title="Invoice summary" class="mb-2">
        <dl class="row small mb-0">
            <dt class="col-4">Order</dt>
            <dd class="col-8">{{ $row->sourceId }}</dd>
            <dt class="col-4">Product</dt>
            <dd class="col-8">{{ $row->productDisplay() }}</dd>
            <dt class="col-4">Serial</dt>
            <dd class="col-8">
                @include('inventory.hardware-fulfilments.fragments.serial-summary', [
                    'serials' => $row->allocatedSerials(),
                    'expected' => $row->expectedSerialQuantity,
                    'compact' => $row->serialDisplay(),
                    'id' => 'hardware-invoice-serial-summary',
                ])
            </dd>
            <dt class="col-4">Payment</dt>
            <dd class="col-8">{{ $row->payment }}</dd>
        </dl>
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
            Issue Invoice
        </button>
    </x-c360.modal-footer>
</form>
