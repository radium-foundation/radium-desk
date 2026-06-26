@props([
    'label',
    'value',
    'icon',
    'color' => 'secondary',
    'href' => null,
])

@php
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if($href)
        href="{{ $href }}"
        class="dashboard-kpi-item text-decoration-none"
    @else
        class="dashboard-kpi-item"
    @endif
>
    <div class="dashboard-kpi-icon bg-{{ $color }}-subtle text-{{ $color }}">
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
    </div>
    <div class="dashboard-kpi-content">
        <div class="dashboard-kpi-label">{{ $label }}</div>
        <div class="dashboard-kpi-value">{{ number_format($value) }}</div>
    </div>
</{{ $tag }}>
