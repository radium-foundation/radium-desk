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
    $breakdownId = 'hw-stuck-breakdown-'.substr(md5(json_encode($breakdown).$total), 0, 10);

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
     data-hardware-stuck-breakdown
     @if($capturedAt > 0) data-hardware-stuck-captured-at="{{ $capturedAt }}" @endif
     @if($version > 0) data-hardware-stuck-version="{{ $version }}" @endif
     @if($stale) data-hardware-stuck-stale="1" @endif>
    <a href="{{ $hardwareUrl(HardwareWorkspaceFilter::NeedsAction->value) }}#dashboard-service-cases-panel"
       class="admin-hardware-stuck-kpi__nav text-decoration-none dashboard-u-hover-lift dashboard-u-focus-ring"
       data-workspace="hardware"
       data-hw-filter="needs_action">
        <div class="dashboard-kpi-icon text-danger">
            <i class="bi bi-cpu" aria-hidden="true"></i>
        </div>
        <div class="dashboard-kpi-content">
            <div class="dashboard-kpi-label">Hardware Stuck Orders</div>
            <div class="admin-hardware-stuck-kpi__value-row">
                <span class="dashboard-kpi-value">{{ number_format($total) }}</span>
                @if($breakdown !== [])
                    <span class="admin-hardware-stuck-kpi__badges"
                          aria-label="Breakdown: @foreach($breakdown as $item){{ number_format($item['count']) }} {{ $item['label'] }}@if(! $loop->last), @endif @endforeach">
                        @foreach($breakdown as $item)
                            <span class="admin-hardware-stuck-kpi__badge admin-hardware-stuck-kpi__badge--{{ $item['tone'] }}"
                                  title="{{ $item['label'] }}">{{ number_format($item['count']) }}</span>
                        @endforeach
                    </span>
                @endif
            </div>
            <div data-hardware-stuck-updated class="dashboard-kpi-updated small text-secondary mb-0" aria-live="polite"></div>
            @if($stale)
                <span class="visually-hidden">Counts refreshing in background</span>
            @endif
        </div>
    </a>
    @if($breakdown !== [])
        <button type="button"
                class="admin-hardware-stuck-kpi__breakdown-trigger"
                data-hardware-stuck-breakdown-trigger
                aria-expanded="false"
                aria-controls="{{ $breakdownId }}"
                aria-label="Show hardware queue breakdown">
            <i class="bi bi-chevron-down" aria-hidden="true"></i>
        </button>
        <div id="{{ $breakdownId }}"
             class="admin-hardware-stuck-kpi__breakdown"
             data-hardware-stuck-breakdown-panel
             role="region"
             aria-label="Hardware needs action breakdown">
            @foreach($breakdown as $item)
                <a href="{{ $hardwareUrl($item['filter']) }}#dashboard-service-cases-panel"
                   class="admin-hardware-stuck-kpi__breakdown-row admin-hardware-stuck-kpi__breakdown-row--{{ $item['tone'] }}"
                   data-workspace="hardware"
                   data-hw-filter="{{ $item['filter'] }}">
                    <span class="admin-hardware-stuck-kpi__breakdown-label">{{ $item['label'] }}</span>
                    <span class="admin-hardware-stuck-kpi__breakdown-count">{{ number_format($item['count']) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</div>