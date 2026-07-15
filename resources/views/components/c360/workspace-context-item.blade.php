@props([
    'label' => null,
    'icon' => null,
])

<span {{ $attributes->merge(['class' => 'workspace-context-strip__item']) }}>
    @if(filled($icon))
        <span class="workspace-context-strip__icon" aria-hidden="true">{{ $icon }}</span>
    @elseif(filled($label))
        <span class="workspace-context-strip__label">{{ $label }}</span>
    @endif
    <span class="workspace-context-strip__value">{{ $slot }}</span>
</span>
