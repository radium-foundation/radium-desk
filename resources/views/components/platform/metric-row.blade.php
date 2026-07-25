@props([
    'metric',
])

@php
    $label = is_array($metric) ? ($metric['label'] ?? '') : $metric->label;
    $value = is_array($metric) ? ($metric['value'] ?? '') : $metric->value;
    $detail = is_array($metric) ? ($metric['detail'] ?? null) : $metric->detail;
    $status = is_array($metric)
        ? ($metric['status'] ?? null)
        : $metric->status?->value;
@endphp

<div class="settings-center-platform-metric">
    <div class="settings-center-platform-metric__text">
        <div class="settings-center-platform-metric__label">{{ $label }}</div>
        @if(filled($detail))
            <div class="settings-center-platform-metric__detail">{{ $detail }}</div>
        @endif
    </div>
    <div class="settings-center-platform-metric__value">
        @if(filled($status))
            <x-platform.status-badge :status="$status" :label="$value" />
        @else
            <span class="settings-center-platform-metric__value-text">{{ $value }}</span>
        @endif
    </div>
</div>
