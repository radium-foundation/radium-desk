@props([
    'label',
    'value',
    'status' => 'neutral',
    'icon' => 'bi-activity',
    'progress' => null,
])

@php
    $statusClass = match ($status) {
        'success' => 'system-settings-health-metric--success',
        'warning' => 'system-settings-health-metric--warning',
        'danger' => 'system-settings-health-metric--danger',
        default => 'system-settings-health-metric--neutral',
    };
@endphp

<div {{ $attributes->merge(['class' => 'system-settings-health-metric ' . $statusClass]) }}>
    <div class="system-settings-health-metric__header">
        <div class="system-settings-health-metric__title">
            <i class="bi {{ $icon }}" aria-hidden="true"></i>
            <span>{{ $label }}</span>
        </div>
        <span class="system-settings-health-metric__badge">{{ $value }}</span>
    </div>
    @if($progress !== null)
        <div class="system-settings-health-metric__bar" role="progressbar"
             aria-valuenow="{{ $progress }}"
             aria-valuemin="0"
             aria-valuemax="100">
            <span style="width: {{ min(100, max(0, $progress)) }}%"></span>
        </div>
    @endif
</div>
