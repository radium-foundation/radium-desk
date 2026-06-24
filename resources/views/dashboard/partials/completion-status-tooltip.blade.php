@props(['order', 'loggedAt'])

@php
    $referenceCreatedAt = $loggedAt ?? $order->created_at ?? now();
@endphp

@include('orders.partials.completion-status-badge', ['order' => $order])

<i class="bi bi-info-circle text-muted ms-1"
   role="img"
   aria-label="Completion status details"
   data-bs-toggle="tooltip"
   data-bs-placement="top"
   data-bs-html="true"
   data-bs-title="{!! $order->completionTooltipHtml($referenceCreatedAt) !!}"></i>
