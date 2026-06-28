@props([
    'order',
    'activeIncident' => null,
])

@php
    $hasGatewayPayment = filled($order->cashfree_payment_id)
        || filled($order->gateway_payment_id)
        || filled($order->payment_date);
    $isActivationComplete = $order->isTransactionLocked();
    $isHighPriority = (bool) ($activeIncident?->high_priority ?? false);
@endphp

<div class="order-workspace-status-chips" role="list" aria-label="Order status">
    <span @class([
        'order-workspace-chip',
        'order-workspace-chip--success' => $hasGatewayPayment,
        'order-workspace-chip--neutral' => ! $hasGatewayPayment,
    ]) role="listitem" data-financial-status="gateway">
        <i class="bi {{ $hasGatewayPayment ? 'bi-credit-card-fill' : 'bi-credit-card' }}" aria-hidden="true"></i>
        Gateway Payment: {{ $hasGatewayPayment ? 'Received' : 'Not recorded' }}
    </span>

    <span @class([
        'order-workspace-chip',
        'order-workspace-chip--success' => $isActivationComplete,
        'order-workspace-chip--warning' => ! $isActivationComplete,
    ]) role="listitem" data-financial-status="activation">
        <i class="bi {{ $isActivationComplete ? 'bi-key-fill' : 'bi-key' }}" aria-hidden="true"></i>
        Activation: {{ $isActivationComplete ? 'Complete' : 'Pending' }}
    </span>

    <span class="order-workspace-chip order-workspace-chip--neutral" role="listitem" data-warranty-status="unknown">
        <i class="bi bi-shield" aria-hidden="true"></i>
        Warranty: Unknown
    </span>

    @if($isHighPriority)
        <span class="order-workspace-chip order-workspace-chip--danger" role="listitem">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            High Priority
        </span>
    @endif
</div>
