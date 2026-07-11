@props([
    'type' => 'info',
    'label',
    'title' => null,
])

@php
    $icons = [
        'pass' => '✓',
        'warning' => '⚠',
        'fail' => '✕',
        'duplicate-clear' => '✓',
        'duplicate-conflict' => '✕',
        'info' => 'ℹ',
    ];
    $resolvedIcon = $icons[$type] ?? 'ℹ';
@endphp

<div {{ $attributes->merge(['class' => 'c360-dialog-status-chip c360-dialog-status-chip--'.$type]) }}
     role="status"
     @if(filled($title)) title="{{ $title }}" @endif>
    <span class="c360-dialog-status-chip-icon" aria-hidden="true">{{ $resolvedIcon }}</span>
    <span class="c360-dialog-status-chip-label">{{ $label }}</span>
</div>
