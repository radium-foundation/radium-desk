@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
@endphp

@include('orders.workspace.partials.summary-cards', [
    'order' => $order,
    'activeIncident' => $activeIncident,
])

<div class="order-workspace-overview-grid">
    @component('orders.workspace.partials.info-card', ['title' => 'Customer', 'icon' => 'bi-person'])
        <dl class="order-workspace-dl order-workspace-dl--wide">
            <dt>Name</dt><dd>{{ $order->customer_name ?: '—' }}</dd>
            <dt>Phone</dt><dd>{{ $order->customer_phone ?: '—' }}</dd>
            <dt>Email</dt>
            <dd>
                @if($order->customer_email)
                    <a href="mailto:{{ $order->customer_email }}">{{ $order->customer_email }}</a>
                @else
                    —
                @endif
            </dd>
        </dl>
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Device', 'icon' => 'bi-phone'])
        <dl class="order-workspace-dl order-workspace-dl--wide">
            <dt>Model</dt><dd>{{ $order->displayDeviceModelName() ?: '—' }}</dd>
            <dt>Serial Number</dt><dd>{{ $order->serial_number ?: '—' }}</dd>
            <dt>Product</dt><dd>{{ $order->product_name ?: '—' }}</dd>
        </dl>
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Order Details', 'icon' => 'bi-receipt'])
        <dl class="order-workspace-dl order-workspace-dl--wide">
            <dt>Order ID</dt><dd class="fw-semibold">{{ $order->order_id }}</dd>
            <dt>Transaction ID</dt><dd>{{ $order->transaction_id ?: '—' }}</dd>
            <dt>Order Completion Status</dt>
            <dd>@include('orders.partials.completion-status-badge', ['order' => $order])</dd>
            <dt>Created</dt><dd>{{ display_app_datetime_24($order->created_at) }}</dd>
            <dt>Last Updated</dt><dd>{{ display_app_datetime_24($order->updated_at) }}</dd>
        </dl>
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Current Repair Status', 'icon' => 'bi-wrench'])
        @if($repairIncident)
            <dl class="order-workspace-dl order-workspace-dl--wide">
                <dt>Reference</dt>
                <dd>
                    <a href="{{ route('incidents.show', $repairIncident) }}" class="text-decoration-none fw-semibold">
                        {{ $repairIncident->display_reference }}
                    </a>
                </dd>
                <dt>Status</dt>
                <dd>@include('incidents.partials.status-badge', ['status' => $repairIncident->status])</dd>
                <dt>Issue</dt><dd>{{ $repairIncident->issueSummary() }}</dd>
                <dt>Engineer</dt><dd>{{ $repairIncident->assignee?->firstName() ?: '—' }}</dd>
            </dl>
        @else
            <p class="text-muted mb-0">No active repair on this order.</p>
        @endif
    @endcomponent
</div>

@include('orders.partials.service-cases-list', ['order' => $order])

<div class="order-workspace-overview-secondary">
    @component('orders.workspace.partials.info-card', ['title' => 'Recent Timeline', 'icon' => 'bi-clock-history'])
        @include('orders.workspace.partials.timeline', [
            'activityTimeline' => $activityTimeline,
            'limit' => 5,
            'showHeading' => false,
        ])
    @endcomponent

    @component('orders.workspace.partials.info-card', ['title' => 'Recent Communication', 'icon' => 'bi-chat-left-dots'])
        <div class="order-workspace-comm-preview">
            <p class="text-muted small mb-0">Communication history will appear here once integrations are connected.</p>
        </div>
    @endcomponent
</div>
