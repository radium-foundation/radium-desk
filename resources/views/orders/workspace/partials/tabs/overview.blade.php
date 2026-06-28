@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
@endphp

<div class="order-workspace-overview-grid">
    @component('orders.workspace.partials.info-card', ['title' => 'Order Details', 'icon' => 'bi-receipt'])
        <dl class="order-workspace-dl order-workspace-dl--wide">
            <dt>Order ID</dt><dd class="order-workspace-dl-value">{{ $order->order_id }}</dd>
            <dt>Transaction ID</dt><dd class="order-workspace-dl-value">{{ $order->transaction_id ?: '—' }}</dd>
            <dt>Completion Status</dt>
            <dd>@include('orders.partials.completion-status-badge', ['order' => $order])</dd>
            <dt>Created</dt><dd class="order-workspace-dl-value">{{ display_app_datetime_24($order->created_at) }}</dd>
            <dt>Last Updated</dt><dd class="order-workspace-dl-value">{{ display_app_datetime_24($order->updated_at) }}</dd>
        </dl>
    @endcomponent

    @if($repairIncident)
        @component('orders.workspace.partials.info-card', ['title' => 'Issue Details', 'icon' => 'bi-wrench'])
            <dl class="order-workspace-dl order-workspace-dl--wide">
                <dt>Reference</dt>
                <dd class="order-workspace-dl-value">
                    <a href="{{ route('incidents.show', $repairIncident) }}" class="text-decoration-none">
                        {{ $repairIncident->display_reference }}
                    </a>
                </dd>
                <dt>Issue</dt>
                <dd class="order-workspace-dl-value">{{ $repairIncident->issueSummary() }}</dd>
                <dt>Product</dt>
                <dd class="order-workspace-dl-value">{{ $order->product_name ?: '—' }}</dd>
            </dl>
        @endcomponent
    @endif
</div>

@include('orders.partials.service-cases-list', ['order' => $order])
