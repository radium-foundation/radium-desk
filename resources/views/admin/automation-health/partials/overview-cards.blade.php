@props([
    'overview' => [],
])

@php
    $cards = [
        [
            'label' => 'Automation Status',
            'value' => $overview['health_label'] ?? '—',
            'icon' => 'bi-activity',
            'color' => $overview['health_badge_class'] ?? 'secondary',
            'is_status' => true,
        ],
        [
            'label' => 'Last Successful Run',
            'value' => $overview['last_success_display'] ?? '—',
            'icon' => 'bi-check-circle',
            'color' => 'success',
        ],
        [
            'label' => 'Last Failed Run',
            'value' => $overview['last_failed_display'] ?? '—',
            'icon' => 'bi-x-circle',
            'color' => 'danger',
        ],
        [
            'label' => 'Executions Today',
            'value' => $overview['executions_today'] ?? 0,
            'icon' => 'bi-lightning-charge',
            'color' => 'primary',
        ],
        [
            'label' => 'Failures Today',
            'value' => $overview['failures_today'] ?? 0,
            'icon' => 'bi-exclamation-triangle',
            'color' => ($overview['failures_today'] ?? 0) > 0 ? 'danger' : 'secondary',
        ],
        [
            'label' => 'Pending Executions',
            'value' => $overview['pending_executions'] ?? 0,
            'icon' => 'bi-hourglass-split',
            'color' => 'warning',
        ],
        [
            'label' => 'Average Execution Time',
            'value' => $overview['average_execution_display'] ?? '—',
            'icon' => 'bi-stopwatch',
            'color' => 'info',
        ],
    ];
@endphp

<section class="mb-4" aria-labelledby="automation-health-overview-heading">
    <h2 id="automation-health-overview-heading" class="h5 mb-3">Overview</h2>

    @if(! empty($overview['health_detail']))
        <p class="small text-muted mb-3">{{ $overview['health_detail'] }}</p>
    @endif

    <div class="dashboard-kpi-strip dashboard-kpi-strip--admin" role="region" aria-label="Automation health overview">
        @foreach($cards as $card)
            <div class="dashboard-kpi-item dashboard-u-surface-card dashboard-u-transition">
                <div class="dashboard-kpi-icon text-{{ $card['color'] }}">
                    <i class="bi {{ $card['icon'] }}" aria-hidden="true"></i>
                </div>
                <div class="dashboard-kpi-content">
                    <div class="dashboard-kpi-label">{{ $card['label'] }}</div>
                    <div class="dashboard-kpi-value">
                        @if(! empty($card['is_status']))
                            <span class="badge bg-{{ $card['color'] }}">{{ $card['value'] }}</span>
                        @else
                            {{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
