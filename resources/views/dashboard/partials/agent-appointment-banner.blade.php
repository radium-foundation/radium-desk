@props([
    'appointment',
])

@php
    $isOverdue = (bool) ($appointment['is_overdue'] ?? false);
@endphp

<div @class([
    'agent-appointment-banner',
    'agent-appointment-banner--overdue' => $isOverdue,
    'agent-appointment-banner--imminent' => ! $isOverdue,
])
     role="status"
     aria-live="polite"
     data-agent-appointment-banner
     data-incident-id="{{ $appointment['incident_id'] }}"
     data-starts-at="{{ $appointment['starts_at'] }}">
    <div class="agent-appointment-banner__header">
        <span class="agent-appointment-banner__indicator" aria-hidden="true">🔴</span>
        <span class="agent-appointment-banner__title">Next Appointment</span>
    </div>
    <div class="agent-appointment-banner__time">{{ $appointment['starts_in_label'] }}</div>
    <div class="agent-appointment-banner__detail">
        <span class="agent-appointment-banner__label">Customer:</span>
        {{ $appointment['customer_name'] }}
    </div>
    @if(filled($appointment['device_model'] ?? null))
        <div class="agent-appointment-banner__detail">
            <span class="agent-appointment-banner__label">Model:</span>
            {{ $appointment['device_model'] }}
        </div>
    @endif
    <button type="button"
            class="btn btn-sm agent-appointment-banner__cta dashboard-u-focus-ring"
            data-agent-open-customer-360="{{ $appointment['incident_id'] }}"
            data-agent-customer-name="{{ $appointment['customer_name'] }}"
            data-agent-open-appointment="true">
        Open Customer360
    </button>
</div>
