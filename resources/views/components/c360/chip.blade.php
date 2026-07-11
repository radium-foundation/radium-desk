@props([
    'label' => null,
    'value',
    'variant' => 'neutral',
    'icon' => null,
    'mono' => false,
])

<span {{ $attributes->merge(['class' => 'c360-chip c360-chip--' . $variant]) }}>
    @if($icon)
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
    @endif
    @if($label)
        <span class="c360-chip-label">{{ $label }}</span>
    @endif
    <span @class(['c360-chip-value', 'font-monospace' => $mono])>{{ $value }}</span>
</span>
