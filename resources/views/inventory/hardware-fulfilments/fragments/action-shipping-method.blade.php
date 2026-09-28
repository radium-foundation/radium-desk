@php
    $currentMethod = $ready->shippingMethod ?? 'shiprocket';
@endphp

<x-c360.section-card title="Shipping method" class="mb-2">
    <p class="small text-muted mb-3">Choose how this order will be shipped. External courier remains available even when Shiprocket has no service.</p>
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.shipping-method.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-shipping-method-form">
        @csrf
        <div class="d-flex flex-column gap-2">
            <label class="form-check">
                <input class="form-check-input"
                       type="radio"
                       name="shipping_method"
                       value="shiprocket"
                       @checked($currentMethod === 'shiprocket')
                       required>
                <span class="form-check-label">Shiprocket</span>
            </label>
            <label class="form-check">
                <input class="form-check-input"
                       type="radio"
                       name="shipping_method"
                       value="external"
                       @checked($currentMethod === 'external')
                       required>
                <span class="form-check-label">Manual / External Courier</span>
            </label>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary mt-3" data-hardware-action-submit>
            Save Shipping Method
        </button>
    </form>
</x-c360.section-card>
