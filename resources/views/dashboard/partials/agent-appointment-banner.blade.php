@props([
    'appointment',
])

@php
    $isOverdue = (bool) ($appointment['is_overdue'] ?? false);
@endphp

<button type="button"
        @class([
            'agent-appointment-banner',
            'agent-appointment-banner--overdue' => $isOverdue,
            'agent-appointment-banner--imminent' => ! $isOverdue,
        ])
        role="status"
        aria-live="polite"
        data-agent-appointment-banner
        data-agent-open-customer-360="{{ $appointment['incident_id'] }}"
        data-agent-customer-name="{{ $appointment['customer_name'] }}"
        data-agent-open-appointment="true"
        data-incident-id="{{ $appointment['incident_id'] }}"
        data-starts-at="{{ $appointment['starts_at'] }}">
    <span class="agent-appointment-banner__eyebrow">Next appointment</span>
    <span class="agent-appointment-banner__time">{{ $appointment['starts_in_label'] }}</span>
    <span class="agent-appointment-banner__detail">{{ $appointment['customer_name'] }}</span>
    @if(filled($appointment['device_model'] ?? null))
        <span class="agent-appointment-banner__detail">{{ $appointment['device_model'] }}</span>
    @endif
</button>
