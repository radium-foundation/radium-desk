@props([
    'order',
    'activeIncident' => null,
])

@php
    $incident = $activeIncident ?? $order->latestIncident();
@endphp

<div class="order-workspace-quick-actions">
    <x-bonvoice.call-button
        :phone="$order->customer_phone"
        :order-id="$order->id"
        :incident-id="$incident?->id"
        class="order-workspace-action-btn"
        icon-class="bi bi-telephone-fill"
        title="Call customer"
        aria-label="Call customer"
    />