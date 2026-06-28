@props([
    'order',
    'activeIncident' => null,
])

@php
    $repairIncident = $activeIncident ?? $order->latestIncident();
    $slaLabel = $repairIncident ? $repairIncident->slaStatus()->label() : 'No active repair';
    $priorityLabel = $repairIncident?->high_priority ? 'High' : ($repairIncident ? 'Normal' : '—');
    $paymentLabel = $order->isTransactionLocked() ? 'Paid' : 'Pending';
    $paymentDetail = $order->transaction_id ?: 'Awaiting transaction ID';
    $warrantyLabel = 'Active';
    $warrantyDetail = 'Standard coverage';
@endphp

<div class="order-workspace-summary-cards">
    <div class="order-workspace-summary-card order-workspace-summary-card--sla">
        <div class="order-workspace-summary-card-icon">
            <i class="bi bi-stopwatch" aria-hidden="true"></i>
        </div>
        <div class="order-workspace-summary-card-label">SLA</div>
        <div class="order-workspace-summary-card-value">{{ $slaLabel }}</div>
    </div>

    <div class="order-workspace-summary-card order-workspace-summary-card--priority">
        <div class="order-workspace-summary-card-icon">
            <i class="bi bi-flag-fill" aria-hidden="true"></i>
        </div>
        <div class="order-workspace-summary-card-label">Priority</div>
        <div class="order-workspace-summary-card-value">{{ $priorityLabel }}</div>
    </div>

    <div class="order-workspace-summary-card order-workspace-summary-card--payment">
        <div class="order-workspace-summary-card-icon">
            <i class="bi bi-credit-card-2-front" aria-hidden="true"></i>
        </div>
        <div class="order-workspace-summary-card-label">Payment</div>
        <div class="order-workspace-summary-card-value">{{ $paymentLabel }}</div>
        <div class="order-workspace-summary-card-detail">{{ $paymentDetail }}</div>
    </div>

    <div class="order-workspace-summary-card order-workspace-summary-card--warranty">
        <div class="order-workspace-summary-card-icon">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
        </div>
        <div class="order-workspace-summary-card-label">Warranty</div>
        <div class="order-workspace-summary-card-value">{{ $warrantyLabel }}</div>
        <div class="order-workspace-summary-card-detail">{{ $warrantyDetail }}</div>
    </div>
</div>
