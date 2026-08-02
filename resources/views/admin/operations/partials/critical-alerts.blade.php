@props([
    'dashboard',
    'briefing' => null,
])

@php
    use App\Enums\AI\AIRiskLevel;
    use App\Enums\IntegrationHealthStatus;
    use App\Services\Platform\PlatformIntegrationHealthOverviewService;

    // Operational alerts only — integration monitoring lives on Platform.
    $supportToday = $dashboard->supportIntelligence['today']
        ?? ($briefing?->snapshot->operations['support']['today'] ?? []);

    $alerts = [];

    $integrationOverview = app(PlatformIntegrationHealthOverviewService::class)->cachedOverview();
    if ($integrationOverview['available'] ?? false) {
        foreach ($integrationOverview['items'] as $item) {
            $status = IntegrationHealthStatus::tryFrom((string) ($item['status'] ?? ''))
                ?? IntegrationHealthStatus::Unavailable;

            if (! in_array($status, [
                IntegrationHealthStatus::Critical,
                IntegrationHealthStatus::Warning,
                IntegrationHealthStatus::Unavailable,
            ], true)) {
                continue;
            }

            $alerts[] = [
                'severity' => in_array($status, [
                    IntegrationHealthStatus::Critical,
                    IntegrationHealthStatus::Unavailable,
                ], true) ? 'danger' : 'warning',
                'title' => ($item['label'] ?? 'Integration').' needs attention',
                'message' => (string) ($item['summary'] ?? $item['detail'] ?? 'Open Platform Integration Health.'),
                'metric' => null,
                'metric_label' => $item['status_label'] ?? $status->label(),
                'action_url' => route('admin.platform.index').'#platform-zone-integration_health',
                'action_label' => 'Open Platform',
            ];
        }
    }

    $missedOverdue = (int) ($supportToday['missed_overdue'] ?? 0);
    if ($missedOverdue > 0) {
        $alerts[] = [
            'severity' => 'warning',
            'title' => 'Overdue support appointments',
            'message' => 'Missed or overdue support appointments need follow-up.',
            'metric' => $missedOverdue,
            'metric_label' => 'Overdue',
            'action_target' => '#operations-tab-today',
        ];
    }

    if ($briefing !== null) {
        foreach ($briefing->risks as $risk) {
            if ($risk->severity !== AIRiskLevel::High) {
                continue;
            }

            $alerts[] = [
                'severity' => 'danger',
                'title' => $risk->title,
                'message' => $risk->message,
                'metric' => null,
                'metric_label' => 'High risk',
                'action_target' => '#operations-tab-today',
            ];
        }
    }
@endphp

<section class="operations-critical-alerts" aria-labelledby="operations-critical-alerts-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <h2 id="operations-critical-alerts-heading" class="h6 mb-0 text-uppercase text-muted fw-semibold">Critical Alerts</h2>
            <p class="text-muted small mb-0">Operational signals. Integration diagnostics are on Platform.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="{{ route('admin.platform.index') }}#platform-zone-critical_alerts" class="btn btn-sm btn-outline-secondary">
                Platform Alerts
            </a>
            @if ($alerts === [])
                <span class="status-badge status-healthy">All clear</span>
            @else
                <span class="status-badge status-danger">{{ number_format(count($alerts)) }} active</span>
            @endif
        </div>
    </div>

    @if ($alerts === [])
        <div class="operations-critical-alerts-clear card border-0 shadow-sm operations-card-hover">
            <div class="card-body py-2 px-3 text-muted small mb-0">
                No critical operational alerts right now.
                <a href="{{ route('admin.platform.index') }}">Open Platform Dashboard</a> for monitoring.
            </div>
        </div>
    @else
        <div class="operations-critical-alerts-grid operations-critical-alerts-feed card border-0 shadow-sm">
            @foreach ($alerts as $alert)
                @php
                    $isLink = ! empty($alert['action_url']);
                    $tag = $isLink ? 'a' : 'button';
                @endphp
                <{{ $tag }}
                    @if ($isLink)
                        href="{{ $alert['action_url'] }}"
                    @else
                        type="button"
                    @endif
                    @class([
                        'operations-critical-alert-card operations-critical-alert-row',
                        'operations-critical-alert-card--danger' => $alert['severity'] === 'danger',
                        'operations-critical-alert-card--warning' => $alert['severity'] === 'warning',
                    ])
                    @if (! empty($alert['action_target']))
                        data-operations-tab-target="{{ $alert['action_target'] }}"
                    @endif
                >
                    <span
                        @class([
                            'operations-critical-alert-severity',
                            'operations-critical-alert-severity--danger' => $alert['severity'] === 'danger',
                            'operations-critical-alert-severity--warning' => $alert['severity'] === 'warning',
                        ])
                        aria-hidden="true"
                    ></span>

                    <span class="operations-critical-alert-content">
                        <span class="operations-critical-alert-title">{{ $alert['title'] }}</span>
                        <span class="operations-critical-alert-message">{{ $alert['message'] }}</span>
                    </span>

                    @if ($alert['metric'] !== null)
                        <span class="operations-critical-alert-metric">
                            <span class="operations-critical-alert-metric-value">{{ number_format($alert['metric']) }}</span>
                            <span class="operations-critical-alert-metric-label">{{ $alert['metric_label'] }}</span>
                        </span>
                    @else
                        <span @class(['status-badge', 'status-' . $alert['severity'], 'operations-critical-alert-priority'])>{{ $alert['metric_label'] }}</span>
                    @endif
                </{{ $tag }}>
            @endforeach
        </div>
    @endif
</section>
