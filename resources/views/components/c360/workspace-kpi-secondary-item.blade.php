@props([
    'label',
])

<span {{ $attributes->merge(['class' => 'workspace-kpi-secondary__item']) }}>
    <span class="workspace-kpi-secondary__label">{{ $label }}</span>
    <span class="workspace-kpi-secondary__value">{{ $slot }}</span>
</span>
