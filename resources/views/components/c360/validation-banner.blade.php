@props([
    'type' => 'info',
    'icon' => null,
    'message',
    'detail' => null,
])

@php
    $icons = [
        'pass' => '✅',
        'warning' => '⚠',
        'fail' => '❌',
        'duplicate-clear' => '✅',
        'duplicate-conflict' => '❌',
        'info' => 'ℹ',
    ];
    $resolvedIcon = $icon ?? ($icons[$type] ?? 'ℹ');
@endphp

<div {{ $attributes->merge(['class' => 'c360-dialog-validation-banner c360-dialog-validation-banner--'.$type]) }}
     role="status">
    <span class="c360-dialog-validation-banner-icon" aria-hidden="true">{{ $resolvedIcon }}</span>
    <div class="c360-dialog-validation-banner-content">
        <p class="c360-dialog-validation-banner-message mb-0">{{ $message }}</p>
        @if(filled($detail))
            <p class="c360-dialog-validation-banner-detail mb-0">{{ $detail }}</p>
        @endif
    </div>
</div>
