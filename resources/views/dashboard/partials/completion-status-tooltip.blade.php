@props(['order', 'loggedAt'])

@php
    $referenceCreatedAt = $loggedAt ?? $order->created_at ?? now();
@endphp

<span class="d-inline-flex align-items-center gap-1">
    @include('orders.partials.completion-status-badge', ['order' => $order])

    <i class="bi bi-info-circle text-muted"
       role="img"
       aria-label="Completion status details"
       data-bs-toggle="tooltip"
       data-bs-placement="top"
       data-bs-html="true"
       data-bs-title="{!! $order->completionTooltipHtml($referenceCreatedAt) !!}"></i>
</span>
