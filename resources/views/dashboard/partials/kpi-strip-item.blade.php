@props([
    'label',
    'value',
    'icon',
    'color' => 'secondary',
    'href' => null,
    'itemClass' => null,
    'kpiAction' => null,
    'workspace' => null,
    'metric' => null,
    'viewOnlyRefresh' => false,
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

    if ($viewOnlyRefresh && $metric) {
        $itemClasses[] = 'dashboard-kpi-item--view-only';
    }
@endphp

<{{ $tag }}
    @if($href)
        href="{{ $href }}"
    @endif
    @if($workspace)
        data-workspace="{{ $workspace }}"
        data-operations-workspace-link
    @endif
    @if($metric)
        data-dashboard-metric="{{ $metric }}"
    @endif
    @if($viewOnlyRefresh && $metric)
        data-view-only-metric="1"
    @endif
    @if($kpiAction)
        data-dashboard-kpi-action="{{ $kpiAction }}"
    @endif
    @class($itemClasses)
>
    <div class="dashboard-kpi-icon text-{{ $color }}">
        <i class="bi {{ $icon }}" aria-hidden="true"></i>
    </div>
    <div class="dashboard-kpi-content">
        <div class="dashboard-kpi-label-row">
            <div class="dashboard-kpi-label">{{ $label }}</div>
            @if($viewOnlyRefresh && $metric)
                <button type="button"
                        class="dashboard-kpi-refresh"
                        data-dashboard-metric-refresh="{{ $metric }}"
                        aria-label="Refresh {{ $label }} count"
                        title="Refresh count">
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
                </button>
            @endif
        </div>
        <div class="dashboard-kpi-value">{{ number_format($value) }}</div>
        @if($viewOnlyRefresh && $metric)
            <div class="dashboard-kpi-updated"
                 data-dashboard-metric-updated="{{ $metric }}"
                 aria-live="polite"></div>
        @endif
    </div>
</{{ $tag }}>
