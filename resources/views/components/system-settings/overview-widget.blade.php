@props([
    'label',
    'value',
    'status' => 'neutral',
    'icon' => 'bi-circle',
    'hint' => null,
])

@php
    $statusClass = match ($status) {
        'success' => 'system-settings-widget--success',
        'warning' => 'system-settings-widget--warning',
        'danger' => 'system-settings-widget--danger',
        default => 'system-settings-widget--neutral',
    };
@endphp

<div {{ $attributes->merge(['class' => 'system-settings-widget ' . $statusClass]) }}>
    <div class="system-settings-widget__icon" aria-hidden="true">
        <i class="bi {{ $icon }}"></i>
    </div>
    <div class="system-settings-widget__body">
        <span class="system-settings-widget__label">{{ $label }}</span>
        <span class="system-settings-widget__value">{{ $value }}</span>
        @if($hint)
            <span class="system-settings-widget__hint">{{ $hint }}</span>
        @endif
    </div>
</div>
