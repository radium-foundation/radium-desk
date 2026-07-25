@php
    $systemSettings = $groupedSettings['system'] ?? collect();
    $queuePending = (int) ($performanceHealth['queue_pending_jobs'] ?? 0);
    $failedJobs = (int) ($performanceHealth['failed_jobs'] ?? 0);
    $cpuAvailable = $performanceHealth['cpu_load']['available'] ?? false;
    $cpuLoad = $performanceHealth['cpu_load']['load'] ?? null;
    $cpuProgress = $cpuAvailable && is_numeric($cpuLoad) ? min(100, (float) $cpuLoad * 25) : null;
    $queueStatus = $failedJobs > 0 ? 'danger' : ($queuePending > 100 ? 'warning' : 'success');
    $realtimeConnStatus = $performanceHealth['realtime_connection_status'] ?? 'unknown';
    $realtimeStatus = match ($realtimeConnStatus) {
        'connected' => 'success',
        'polling', 'connecting' => 'warning',
        'disconnected' => 'danger',
        default => 'neutral',
    };
@endphp

<x-system-settings.section
    id="category-system"
    icon="bi-activity"
    title="Diagnostics"
    description="Platform health snapshots and live system metrics."
>
    <div class="system-settings-health-grid-widgets mb-3">
        <x-system-settings.health-metric
            label="CPU Load"
            :value="$cpuAvailable ? (string) $cpuLoad : 'Unavailable'"
            :status="$cpuAvailable && is_numeric($cpuLoad) && (float) $cpuLoad > 2 ? 'warning' : 'success'"
            icon="bi-cpu"
            :progress="$cpuProgress"
        />
        <x-system-settings.health-metric
            label="Memory"
            :value="$performanceHealth['memory']['current'] ?? '—'"
            status="success"
            icon="bi-memory"
        />
        <x-system-settings.health-metric
            label="Queue"
            :value="number_format($queuePending) . ' pending'"
            :status="$queueStatus"
            icon="bi-layers"
            :progress="min(100, $queuePending / 5)"
        />
        <x-system-settings.health-metric
            label="Failed Jobs"
            :value="number_format($failedJobs)"
            :status="$failedJobs > 0 ? 'danger' : 'success'"
            icon="bi-exclamation-triangle"
        />
        <x-system-settings.health-metric
            label="WebSocket"
            :value="ucfirst($realtimeConnStatus)"
            :status="$realtimeStatus"
            icon="bi-broadcast"
        />
        <x-system-settings.health-metric
            label="Response Time"
            :value="number_format($performanceHealth['dashboard_poll_interval_ms']) . ' ms'"
            status="neutral"
            icon="bi-graph-up"
        />
    </div>

    <x-system-settings.card
        title="System Details"
        description="Read-only snapshot computed at page load."
    >
        <div class="system-settings-details-grid">
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Performance profile</span>
                <span class="system-settings-detail__value">{{ ucwords(str_replace('_', ' ', $performanceHealth['performance_profile'])) }}</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Dashboard polling</span>
                <span class="system-settings-detail__value">{{ number_format($performanceHealth['dashboard_poll_interval_ms']) }} ms</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Notification polling</span>
                <span class="system-settings-detail__value">{{ number_format($performanceHealth['notification_poll_interval_ms']) }} ms</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Broadcast driver</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['broadcast_driver'] }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Dashboard live mode</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['dashboard_live_mode'] }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Realtime provider</span>
                <span class="system-settings-detail__value"><code>{{ $performanceHealth['realtime_provider'] ?? 'polling' }}</code></span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Memory peak</span>
                <span class="system-settings-detail__value">{{ $performanceHealth['memory']['peak'] ?? '—' }}</span>
            </div>
            <div class="system-settings-detail">
                <span class="system-settings-detail__label">Polling active</span>
                <span class="system-settings-detail__value">{{ ! empty($performanceHealth['realtime_polling_active']) ? 'Yes' : 'No' }}</span>
            </div>
        </div>

        <details class="system-settings-health-details mt-3">
            <summary>Hybrid Realtime features</summary>
            <ul class="system-settings-health-features">
                @foreach($performanceHealth['hybrid_realtime_features'] as $feature)
                    <li>
                        <span>{{ $feature['label'] }}</span>
                        @if(! $feature['wired'])
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--neutral">Not wired</span>
                        @elseif($feature['enabled'])
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--success">On</span>
                        @else
                            <span class="system-settings-status-pill system-settings-status-pill--sm system-settings-status-pill--neutral">Off</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </details>
    </x-system-settings.card>
</x-system-settings.section>
