<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.serials.store', $fulfilment) }}"
      data-hardware-action-form
      id="hardware-action-serial-form">
    @csrf
    <x-c360.section-card title="Available serial" class="mb-2">
        @forelse($requirements as $line)
            <div class="mb-3" data-hardware-serial-picker data-item-id="{{ $line['commerce_order_item_id'] }}" data-search-url="{{ $searchUrl }}">
                <p class="small mb-2">{{ $line['description'] }} · Qty {{ $line['qty'] }}</p>
                <label class="form-label" for="hardware-action-serial-search-{{ $line['commerce_order_item_id'] }}">Search / select serial</label>
                <input type="search"
                       id="hardware-action-serial-search-{{ $line['commerce_order_item_id'] }}"
                       class="form-control"
                       data-hardware-serial-query
                       placeholder="Serial number"
                       autocomplete="off">
                <div class="small mt-2" data-hardware-serial-results aria-live="polite"></div>
                <ul class="list-unstyled small mb-0 mt-2" data-hardware-serial-selected></ul>
            </div>
        @empty
            <p class="small text-muted mb-0">No allocatable lines on this fulfilment.</p>
        @endforelse
        @if(! ($canAllocate ?? false))
            <p class="small text-danger mb-0">Serial allocation is not available for this order yet.</p>
        @endif
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit {{ ($canAllocate ?? false) ? '' : 'disabled' }}>
            Allocate Serial
        </button>
    </x-c360.modal-footer>
</form>
