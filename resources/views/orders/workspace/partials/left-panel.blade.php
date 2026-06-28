@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
@endphp

<aside class="order-workspace-left" aria-label="Order summary">
    @component('orders.workspace.partials.info-card', [
        'title' => 'Customer',
        'icon' => 'bi-person-circle',
        'compact' => true,
    ])
        <dl class="order-workspace-dl">
            <dt>Name</dt>
            <dd>{{ $order->customer_name ?: '—' }}</dd>
            <dt>Phone</dt>
            <dd>{{ $order->customer_phone ?: '—' }}</dd>
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

    @component('orders.workspace.partials.info-card', [
        'title' => 'Device',
        'icon' => 'bi-phone',
        'compact' => true,
    ])
        <dl class="order-workspace-dl">
            <dt>Model</dt>
            <dd>{{ $order->displayDeviceModelName() ?: '—' }}</dd>
            <dt>Serial</dt>
            <dd>{{ $order->serial_number ?: '—' }}</dd>
        </dl>
    @endcomponent

    @component('orders.workspace.partials.info-card', [
        'title' => 'Repair Status',
        'icon' => 'bi-wrench-adjustable',
        'compact' => true,
    ])
        @if($repairIncident)
            <div class="order-workspace-repair-status">
                <div class="fw-semibold">{{ $repairIncident->display_reference }}</div>
                <div class="small text-muted mt-1">
                    @include('incidents.partials.status-badge', ['status' => $repairIncident->status])
                </div>
                <div class="small mt-2">
                    Engineer: {{ $repairIncident->assignee?->firstName() ?: 'Unassigned' }}
                </div>
            </div>
        @else
            <p class="text-muted small mb-0">No active repair.</p>
        @endif
    @endcomponent

    @component('orders.workspace.partials.info-card', [
        'title' => 'Timeline',
        'icon' => 'bi-clock-history',
        'compact' => true,
    ])
        @include('orders.workspace.partials.timeline', [
            'activityTimeline' => $activityTimeline,
            'limit' => 3,
            'compact' => true,
            'showHeading' => false,
        ])
    @endcomponent

    @component('orders.workspace.partials.info-card', [
        'title' => 'Recent Communications',
        'icon' => 'bi-chat-dots',
        'compact' => true,
    ])
        <ul class="order-workspace-comm-list list-unstyled mb-0">
            <li>
                <span class="order-workspace-comm-type"><i class="bi bi-telephone"></i> Call</span>
                <span class="text-muted small">No recent calls</span>
            </li>
            <li>
                <span class="order-workspace-comm-type"><i class="bi bi-whatsapp"></i> WhatsApp</span>
                <span class="text-muted small">No recent messages</span>
            </li>
            <li>
                <span class="order-workspace-comm-type"><i class="bi bi-envelope"></i> Email</span>
                <span class="text-muted small">No recent emails</span>
            </li>
        </ul>
    @endcomponent
</aside>
