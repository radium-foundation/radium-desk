@props([
    'label',
    'tone' => 'neutral',
    'size' => null,
])

<span @class([
    'settings-center-status-pill',
    'settings-center-status-pill--'.$tone,
    'settings-center-status-pill--sm' => $size === 'sm',
])>
    <span class="settings-center-status-pill__dot" aria-hidden="true"></span>
    {{ $label }}
</span>
