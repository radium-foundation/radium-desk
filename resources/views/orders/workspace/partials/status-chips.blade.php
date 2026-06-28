@props([
    'order',
    'activeIncident' => null,
])

@php
    $isPaymentComplete = $order->isTransactionLocked();
    $isHighPriority = (bool) ($activeIncident?->high_priority ?? false);
    $repairStatus = $activeIncident?->status->label();
@endphp

<div class="order-workspace-status-chips" role="list" aria-label="Order status">
    @if($repairStatus)
        <span class="order-workspace-chip order-workspace-chip--info" role="listitem">
            <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
            {{ $repairStatus }}
        </span>
    @endif

    <span @class([
        'order-workspace-chip',
        'order-workspace-chip--success' => $isPaymentComplete,
        'order-workspace-chip--warning' => ! $isPaymentComplete,
    ]) role="listitem">
        <i class="bi {{ $isPaymentComplete ? 'bi-check-circle-fill' : 'bi-hourglass-split' }}" aria-hidden="true"></i>
        {{ $isPaymentComplete ? 'Payment Complete' : 'Payment Pending' }}
    </span>

    <span class="order-workspace-chip order-workspace-chip--neutral" role="listitem">
        <i class="bi bi-shield" aria-hidden="true"></i>
        Warranty Unknown
    </span>

    @if($isHighPriority)
        <span class="order-workspace-chip order-workspace-chip--danger" role="listitem">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            High Priority
        </span>
    @endif

    @include('orders.partials.completion-status-badge', ['order' => $order])
</div>
