@props([
    'summary',
])

@php
    use App\Enums\HardwareWorkspaceFilter;
    use App\Enums\HardwareWorkspaceScope;

    $total = (int) ($summary['needs_action_total'] ?? 0);
    $breakdown = $summary['breakdown'] ?? [];

    $hardwareUrl = static function (?string $hwFilter = null): string {
        return route('dashboard', array_filter([
            'workspace' => 'hardware',
            'hw_scope' => HardwareWorkspaceScope::Active->value,
            'hw_filter' => $hwFilter,
        ], static fn ($value) => $value !== null && $value !== ''));
    };
@endphp

<div class="dashboard-kpi-item dashboard-u-surface-card admin-hardware-stuck-kpi"
     role="group"
     aria-label="Hardware stuck orders">
    <a href="{{ $hardwareUrl(HardwareWorkspaceFilter::NeedsAction->value) }}#dashboard-service-cases-panel"
       class="admin-hardware-stuck-kpi__head text-decoration-none dashboard-u-hover-lift dashboard-u-focus-ring"
       data-workspace="hardware"
       data-hw-filter="needs_action">
        <div class="dashboard-kpi-icon text-danger">
            <i class="bi bi-cpu" aria-hidden="true"></i>
        </div>
        <div class="dashboard-kpi-content">
            <div class="dashboard-kpi-label">Hardware Stuck Orders</div>
            <div class="dashboard-kpi-value">{{ number_format($total) }}</div>
            <p class="admin-hardware-stuck-kpi__meta mb-0">
                @if($total > 0)
                    Orders blocked in fulfilment — open the matching queue
                @else
                    No fulfilment blockers in Needs Action queues
                @endif
            </p>
        </div>
    </a>
    @if($breakdown !== [])
        <div class="admin-hardware-stuck-kpi__chips" role="list" aria-label="Hardware needs action breakdown">
            @foreach($breakdown as $item)
                <a href="{{ $hardwareUrl($item['filter']) }}#dashboard-service-cases-panel"
                   class="admin-hardware-stuck-chip admin-hardware-stuck-chip--{{ $item['tone'] }}"
                   role="listitem"
                   data-workspace="hardware"
                   data-hw-filter="{{ $item['filter'] }}">
                    <span class="admin-hardware-stuck-chip__count">{{ number_format($item['count']) }}</span>
                    <span class="admin-hardware-stuck-chip__label">{{ $item['label'] }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>
