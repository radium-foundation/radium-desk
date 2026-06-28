@props([
    'order',
])

<section class="order-workspace-customer-story" data-customer-story aria-label="Customer story">
    <h2 class="order-workspace-customer-story-heading">Customer Story</h2>

    <div class="order-workspace-customer-story-identity" data-customer-story-customer>
        <p class="order-workspace-customer-story-name">
            @if($order->customer_phone)
                <a href="tel:{{ $order->customer_phone }}" class="order-workspace-summary-link">
                    {{ $order->customer_name ?: '—' }}
                </a>
            @else
                {{ $order->customer_name ?: '—' }}
            @endif
        </p>
        @if($order->customer_phone)
            <p class="order-workspace-customer-story-detail">
                <a href="tel:{{ $order->customer_phone }}">{{ $order->customer_phone }}</a>
            </p>
        @endif
        @if($order->customer_email)
            <p class="order-workspace-customer-story-detail">{{ $order->customer_email }}</p>
        @endif
    </div>

    @if($order->displayDeviceModelName() || $order->serial_number)
        <div class="order-workspace-customer-story-device" data-customer-story-device>
            <h3 class="order-workspace-customer-story-label">Device</h3>
            <p class="order-workspace-customer-story-value">{{ $order->displayDeviceModelName() ?: '—' }}</p>
            @if($order->serial_number)
                <p class="order-workspace-customer-story-detail font-monospace">{{ $order->serial_number }}</p>
            @endif
        </div>
    @endif

    <p class="order-workspace-customer-story-placeholder" data-customer-story-placeholder>
        Customer summary will appear as more operational data becomes available.
    </p>
</section>
