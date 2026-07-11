@props([
    'value',
    'label' => 'Value',
    'toast' => null,
])

<button type="button"
        {{ $attributes->merge(['class' => 'c360-dialog-copy-btn']) }}
        data-copyable-identifier
        data-copy-value="{{ $value }}"
        data-copy-toast="{{ $toast ?? $label.' copied' }}"
        title="Copy {{ $label }}"
        aria-label="Copy {{ $label }}">
    <span class="c360-dialog-copy-btn-icon" aria-hidden="true">📋</span>
    <span class="visually-hidden">Copy</span>
</button>
