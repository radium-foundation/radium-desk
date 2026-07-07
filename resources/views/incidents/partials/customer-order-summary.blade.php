@props(['incident'])

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">{{ config('ui.service_case.customer_order_heading') }}</h2>
    </div>
    <div class="card-body py-3">
        <div class="row g-3 small">
            <div class="col-sm-6 col-lg-4">
                <span class="text-muted d-block">Customer</span>
                <span class="fw-semibold">{{ $incident->order?->customer_name ?: '—' }}</span>
            </div>
            <div class="col-sm-6 col-lg-4">
                <span class="text-muted d-block">Order</span>
                @if($incident->order)
                    <x-order-identifier
                        :order="$incident->order"
                        :href="route('orders.show', $incident->order)"
                        class="fw-semibold"
                    />
                @else
                    <span class="fw-semibold">—</span>
                @endif
            </div>
            <div class="col-sm-6 col-lg-4">
                <span class="text-muted d-block">Product</span>
                <span class="fw-semibold">{{ $incident->order?->product_name ?: '—' }}</span>
            </div>
            <div class="col-sm-6 col-lg-4">
                <span class="text-muted d-block">Serial Number</span>
                @if(filled($incident->order?->serial_number))
                    <x-copyable-identifier
                        :value="$incident->order->serial_number"
                        class="fw-semibold font-monospace"
                    />
                @else
                    <span class="fw-semibold">—</span>
                @endif
            </div>
            <div class="col-sm-6 col-lg-4">
                <span class="text-muted d-block">Source</span>
                <span class="fw-semibold">{{ $incident->source->label() }}</span>
            </div>
        </div>
    </div>
</div>
