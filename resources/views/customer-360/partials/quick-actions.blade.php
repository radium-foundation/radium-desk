<section class="customer-360-section" data-customer-360-section="quick-actions" aria-labelledby="customer-360-actions-heading">
    <h3 class="customer-360-section-title" id="customer-360-actions-heading">Quick Actions</h3>
    <div class="customer-360-quick-actions">
        @if($order)
            <a href="{{ route('orders.show', $order) }}"
               class="btn btn-outline-secondary btn-sm customer-360-quick-action">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
                Open Order
            </a>
        @endif
        <a href="{{ route('incidents.show', $incident) }}"
           class="btn btn-outline-secondary btn-sm customer-360-quick-action">
            <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
            Open Case
        </a>
        @if(filled($device['serial_number'] ?? null))
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-customer-360-copy="serial"
                    data-copy-value="{{ $device['serial_number'] }}">
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                Copy Serial
            </button>
        @endif
        @if(filled($customer['mobile'] ?? null))
            <button type="button"
                    class="btn btn-outline-secondary btn-sm customer-360-quick-action"
                    data-customer-360-copy="mobile"
                    data-copy-value="{{ $customer['mobile'] }}">
                <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
                Copy Mobile
            </button>
        @endif
    </div>
</section>
