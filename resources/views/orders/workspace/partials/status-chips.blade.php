@props([
    'order',
    'activeIncident' => null,
])

@php
    $isPaymentComplete = $order->isTransactionLocked();
    $isHighPriority = (bool) ($activeIncident?->high_priority ?? false);
@endphp

<div class="order-workspace-status-chips" role="list" aria-label="Order status">
    @include('orders.partials.completion-status-badge', ['order' => $order])

    <span class="order-workspace-chip {{ $isPaymentComplete ? 'order-workspace-chip--success' : 'order-workspace-chip--warning' }}" role="listitem">
        <i class="bi {{ $isPaymentComplete ? 'bi-check-circle-fill' : 'bi-hourglass-split' }}" aria-hidden="true"></i>
        {{ $isPaymentComplete ? 'Payment Complete' : 'Payment Pending' }}
    </span>

    <span class="order-workspace-chip order-workspace-chip--info" role="listitem">
        <i class="bi bi-shield-check" aria-hidden="true"></i>
        Warranty Active
    </span>

    @if($activeIncident)
        <span class="order-workspace-chip order-workspace-chip--neutral" role="listitem">
            <i class="bi bi-truck" aria-hidden="true"></i>
            Pickup Pending
        </span>
    @endif

    @if($isHighPriority)
        <span class="order-workspace-chip order-workspace-chip--danger" role="listitem">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            High Priority
        </span>
    @endif
</div>
