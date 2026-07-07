@props([
    'value',
])

<button type="button"
        {{ $attributes->class(['copyable-identifier']) }}
        data-copyable-identifier="serial"
        data-copy-value="{{ $value }}"
        data-copy-toast="Serial number copied"
        title="Click to copy serial number"
        aria-label="Click to copy serial number">
    {{ $value }}
</button>
