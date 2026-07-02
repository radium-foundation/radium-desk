@props([
    'value',
    'label' => 'Value',
    'copyKey' => 'value',
])

@if(filled($value))
    <span {{ $attributes->merge(['class' => 'customer-360-copyable-value']) }}>
        {{ $slot->isEmpty() ? $value : $slot }}
        <button type="button"
                class="customer-360-inline-copy"
                data-customer-360-copy="{{ $copyKey }}"
                data-copy-value="{{ $value }}"
                data-copy-label="{{ $label }}"
                title="Copy"
                aria-label="Copy {{ $label }}"
                data-bs-toggle="tooltip"
                data-bs-placement="top">
            <i class="bi bi-clipboard" aria-hidden="true" data-customer-360-copy-icon></i>
            <span class="customer-360-inline-copy-check" aria-hidden="true" hidden>✓</span>
        </button>
    </span>
@else
    Not Available
@endif
