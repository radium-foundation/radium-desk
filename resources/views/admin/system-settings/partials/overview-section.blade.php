@php
    $connectionStatus = $realtimeHealth['connection_status'] ?? 'unknown';
    $connectionLabel = match ($connectionStatus) {
        'connected' => 'Connected',
        'connecting' => 'Connecting',
        'polling' => 'Polling',
        'offline' => 'Offline',
        'disconnected' => 'Disconnected',
        default => 'Unknown',
    };
    $connectionWidgetStatus = match ($connectionStatus) {
        'connected' => 'success',
        'connecting', 'polling' => 'warning',
        'disconnected' => 'danger',
        default => 'neutral',
    };

    $queuePending = (int) ($performanceHealth['queue_pending_jobs'] ?? 0);
    $failedJobs = (int) ($performanceHealth['failed_jobs'] ?? 0);
    $queueStatus = $failedJobs > 0 ? 'warning' : ($queuePending > 100 ? 'warning' : 'success');
    $queueLabel = $failedJobs > 0 ? $failedJobs . ' failed' : 'Healthy';

    $cpuAvailable = $performanceHealth['cpu_load']['available'] ?? false;
    $cpuLoad = $performanceHealth['cpu_load']['load'] ?? '—';
    $cpuStatus = $cpuAvailable && is_numeric($cpuLoad) && (float) $cpuLoad > 2 ? 'warning' : 'success';

    $memoryCurrent = $performanceHealth['memory']['current'] ?? '—';
    $memoryStatus = 'success';

    $browserSyncEnabled = collect($performanceHybridRealtimeSettings)
        ->firstWhere('key', 'hybrid_realtime.desktop_notifications');
    $browserSyncValue = $browserSyncEnabled
        ? (filter_var($browserSyncEnabled['value'], FILTER_VALIDATE_BOOLEAN) ? 'Enabled' : 'Disabled')
        : '—';
    $browserSyncStatus = $browserSyncValue === 'Enabled' ? 'success' : 'neutral';

    $workersOnline = $queuePending < 500;
    $pollingMs = number_format($performanceHealth['dashboard_poll_interval_ms'] ?? 0);
@endphp

<x-system-settings.section
    id="section-overview"
    icon="bi-grid-1x2"
    title="Overview"
    description="At-a-glance platform health and operational status."
    :searchable="false"
>
    <div class="system-settings-overview-grid">
        <x-system-settings.overview-widget
            label="Realtime"
            :value="$connectionLabel"
            :status="$connectionWidgetStatus"
            icon="bi-broadcast"
        />
        <x-system-settings.overview-widget
            label="Polling"
            :value="$pollingMs . ' ms'"
            status="neutral"
            icon="bi-arrow-repeat"
        />
        <x-system-settings.overview-widget
            label="Queue"
            :value="$queueLabel"
            :status="$queueStatus"
            icon="bi-layers"
            :hint="$queuePending . ' pending'"
        />
        <x-system-settings.overview-widget
            label="Browser Sync"
            :value="$browserSyncValue"
            :status="$browserSyncStatus"
            icon="bi-window"
        />
        <x-system-settings.overview-widget
            label="Memory"
            :value="$memoryCurrent"
            :status="$memoryStatus"
            icon="bi-memory"
            :hint="'Peak ' . ($performanceHealth['memory']['peak'] ?? '—')"
        />
        <x-system-settings.overview-widget
            label="Workers"
            :value="$workersOnline ? 'Online' : 'Busy'"
            :status="$workersOnline ? 'success' : 'warning'"
            icon="bi-cpu"
        />
        <x-system-settings.overview-widget
            label="CPU Load"
            :value="$cpuLoad"
            :status="$cpuStatus"
            icon="bi-speedometer"
        />
        <x-system-settings.overview-widget
            label="Response Time"
            :value="$pollingMs . ' ms'"
            status="neutral"
            icon="bi-graph-up"
            hint="Dashboard poll interval"
        />
    </div>
</x-system-settings.section>
