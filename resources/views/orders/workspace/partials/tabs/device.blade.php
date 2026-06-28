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
                <span class="font-monospace">{{ $order->serial_number }}</span>
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
    </dl>
@endcomponent
