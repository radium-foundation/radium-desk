@props([
    'label',
    'value',
    'icon',
    'color' => 'secondary',
    'href' => null,
    'itemClass' => null,
])

@php
    $tag = $href ? 'a' : 'div';
    $itemClasses = [
        'dashboard-kpi-item',
        'dashboard-u-surface-card',
        'dashboard-u-transition',
    ];

    if ($itemClass) {
        $itemClasses[] = $itemClass;
    }

    if ($href) {
        $itemClasses[] = 'text-decoration-none';
        $itemClasses[] = 'dashboard-u-hover-lift';
        $itemClasses[] = 'dashboard-u-focus-ring';
    }
@endphp

<{{ $tag }}
    @if($href)
        href="{{ $href }}"
    @endif
    @class($itemClasses)
>
    <div class="dashboard-kpi-icon text-{{ $color }}">
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
    </div>
    <div class="dashboard-kpi-content">
        <div class="dashboard-kpi-label">{{ $label }}</div>
        <div class="dashboard-kpi-value">{{ number_format($value) }}</div>
    </div>
</{{ $tag }}>
