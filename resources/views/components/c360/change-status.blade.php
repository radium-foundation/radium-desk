@props([
    'unchangedText' => 'No changes detected',
])

<div {{ $attributes->merge(['class' => 'c360-dialog-change-status c360-dialog-change-status--unchanged']) }}
     data-c360-change-status
     aria-live="polite">
    <span data-c360-change-status-text>{{ $unchangedText }}</span>
</div>
