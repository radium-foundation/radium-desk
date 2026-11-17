@props([
    'summary',
    'stuckMeta' => null,
])

@php
    use App\Enums\HardwareWorkspaceFilter;
    use App\Enums\HardwareWorkspaceScope;

    $total = (int) ($summary['needs_action_total'] ?? 0);
    $breakdown = $summary['breakdown'] ?? [];
    $refreshMs = max(5000, (int) config('dashboard.hardware_counts_ttl_seconds', 30) * 1000);
    $capturedAt = (int) ($stuckMeta['captured_at'] ?? 0);
    $version = (int) ($stuckMeta['version'] ?? 0);
    $stale = (bool) ($stuckMeta['stale'] ?? false);

    $hardwareUrl = static function (?string $hwFilter = null): string {
        return route('dashboard', array_filter([
            'workspace' => 'hardware',
            'hw_scope' => HardwareWorkspaceScope::Active->value,
            'hw_filter' => $hwFilter,
        ], static fn ($value) => $value !== null && $value !== ''));
    };
@endphp

<div class="dashboard-kpi-item dashboard-u-surface-card admin-hardware-stuck-kpi @if($stale) admin-hardware-stuck-kpi--stale @endif"
     role="group"
     aria-label="Hardware stuck orders"
     data-hardware-stuck-loaded="1"
     data-hardware-stuck-url="{{ route('dashboard.hardware.stuck-summary') }}"
     data-hardware-stuck-refresh-ms="{{ $refreshMs }}"
     @if($capturedAt > 0) data-hardware-stuck-captured-at="{{ $capturedAt }}" @endif
     @if($version > 0) data-hardware-stuck-version="{{ $version }}" @endif
     @if($stale) data-hardware-stuck-stale="1" @endif>
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
                @if($stale)
                    <span class="admin-hardware-stuck-kpi__stale text-secondary">· refreshing in background</span>
                @endif
            </p>
            <div data-hardware-stuck-updated class="dashboard-kpi-updated small text-secondary mb-0" aria-live="polite"></div>
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
