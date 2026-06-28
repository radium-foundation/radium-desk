@props([
    'order',
    'activeIncident' => null,
    'activityTimeline',
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
    $today = now()->startOfDay();
    $todayTimeline = $activityTimeline->filter(
        fn ($entry) => $entry->occurredAt->greaterThanOrEqualTo($today)
    );
@endphp

<aside class="order-workspace-left" aria-label="Quick summary">
    <div class="order-workspace-summary">
        <h2 class="order-workspace-summary-heading">Quick Summary</h2>

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Customer</h3>
            <p class="order-workspace-summary-value">
                @if($order->customer_phone)
                    <a href="tel:{{ $order->customer_phone }}" class="order-workspace-summary-link">
                        {{ $order->customer_name ?: '—' }}
                    </a>
                @else
                    {{ $order->customer_name ?: '—' }}
                @endif
            </p>
            @if($order->customer_phone)
                <p class="order-workspace-summary-detail">
                    <a href="tel:{{ $order->customer_phone }}">{{ $order->customer_phone }}</a>
                </p>
            @endif
        </section>

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Device</h3>
            <p class="order-workspace-summary-value">{{ $order->displayDeviceModelName() ?: '—' }}</p>
            @if($order->serial_number)
                <p class="order-workspace-summary-detail font-monospace">{{ $order->serial_number }}</p>
            @endif
        </section>

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Repair Status</h3>
            @if($repairIncident)
                <p class="order-workspace-summary-value">{{ $repairIncident->display_reference }}</p>
                <div class="order-workspace-summary-detail">
                    @include('incidents.partials.status-badge', ['status' => $repairIncident->status])
                </div>
            @else
                <p class="order-workspace-summary-value text-muted">No active repair</p>
            @endif
        </section>

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Engineer</h3>
            <p class="order-workspace-summary-value">
                {{ $repairIncident?->assignee?->firstName() ?: 'Unassigned' }}
            </p>
        </section>

        <section class="order-workspace-summary-section">
            <div class="order-workspace-summary-section-header">
                <h3 class="order-workspace-summary-label mb-0">Today's Timeline</h3>
                <button type="button"
                        class="btn btn-link btn-sm p-0 order-workspace-summary-link-btn"
                        data-workspace-tab-trigger="timeline">
                    View all
                </button>
            </div>
            @include('orders.workspace.partials.timeline', [
                'activityTimeline' => $todayTimeline,
                'limit' => 3,
                'compact' => true,
                'showHeading' => false,
                'emptyMessage' => 'No activity recorded today.',
            ])
        </section>

        <section class="order-workspace-summary-section">
            <h3 class="order-workspace-summary-label">Communications</h3>
            @include('orders.workspace.partials.communication-summary', ['compact' => true])
        </section>
    </div>
</aside>
