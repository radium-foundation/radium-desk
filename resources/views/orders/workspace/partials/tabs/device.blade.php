@props([
    'order',
])

@component('orders.workspace.partials.info-card', ['title' => 'Device Information', 'icon' => 'bi-phone'])
    <dl class="order-workspace-dl order-workspace-dl--wide">
        <dt>Model</dt>
        <dd>{{ $order->displayDeviceModelName() ?: '—' }}</dd>

        <dt>Assigned By</dt>
        <dd>{{ $order->deviceModelAssigner?->name ?: '—' }}</dd>

        <dt>Assigned At</dt>
        <dd>{{ $order->device_model_assigned_at ? display_app_datetime($order->device_model_assigned_at) : '—' }}</dd>

        <dt>Serial Number</dt>
        <dd>
            @if($order->serial_number)
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <x-copyable-identifier
                        :value="$order->serial_number"
                        class="font-monospace"
                    />
                    @include('orders.partials.serial-validation-badge', ['order' => $order])
                </div>
                @include('orders.workspace.partials.oem-warranty-check', ['order' => $order])
            @else
                —
            @endif
        </dd>

        <dt>Entered By</dt>
        <dd>{{ $order->serialEnterer?->name ?: '—' }}</dd>

        <dt>Entered At</dt>
        <dd>{{ $order->serial_entered_at ? display_app_datetime($order->serial_entered_at) : '—' }}</dd>

        <dt>Product Name</dt>
        <dd>{{ $order->product_name ?: '—' }}</dd>

        <dt>Warranty</dt>
        <dd class="text-muted small">RadiumBox enrichment warranty/AMC remains authoritative for service status. OEM portal checks above are stored separately.</dd>
    </dl>
@endcomponent
