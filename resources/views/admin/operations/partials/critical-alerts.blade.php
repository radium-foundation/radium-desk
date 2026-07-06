@props([
    'dashboard',
    'briefing' => null,
])

@php
    use App\Enums\AI\AIRiskLevel;

    $cashfreeHealth = $dashboard->cashfreeHealth ?? [];
    $radiumBoxHealth = $dashboard->radiumBoxHealth ?? [];
    $supportToday = $dashboard->supportIntelligence['today'] ?? [];

    $alerts = [];

    $paidMissing = (int) ($cashfreeHealth['paid_without_desk_order'] ?? 0);
    if ($paidMissing > 0) {
        $alerts[] = [
            'severity' => 'danger',
            'title' => 'Cashfree paid orders missing Desk records',
            'message' => 'Paid payments need order recovery.',
            'metric' => $paidMissing,
            'metric_label' => 'Paid missing',
            'action_label' => 'Review Cashfree health',
            'action_target' => '#operations-health-trigger-cashfree',
        ];
    }

    $activeWebhookFailures = (int) ($cashfreeHealth['active_failed_webhooks'] ?? 0);
    if ($activeWebhookFailures > 0) {
        $alerts[] = [
            'severity' => 'danger',
            'title' => 'Cashfree webhook failures',
            'message' => 'Actionable webhook failures require recovery.',
            'metric' => $activeWebhookFailures,
            'metric_label' => 'Failed webhooks',
            'action_label' => 'Review Cashfree health',
            'action_target' => '#operations-health-trigger-cashfree',
        ];
    }

    $failedSyncs = (int) ($radiumBoxHealth['failed_syncs'] ?? 0);
    if (($radiumBoxHealth['enabled'] ?? false) && $failedSyncs > 0) {
        $alerts[] = [
            'severity' => 'danger',
            'title' => 'RadiumBox sync failures',
            'message' => 'Order syncs failed and need attention.',
            'metric' => $failedSyncs,
            'metric_label' => 'Failed syncs',
            'action_label' => 'Review RadiumBox health',
            'action_target' => '#operations-health-trigger-radiumbox',
        ];
    }

    $missedOverdue = (int) ($supportToday['missed_overdue'] ?? 0);
    if ($missedOverdue > 0) {
        $alerts[] = [
            'severity' => 'warning',
            'title' => 'Overdue support appointments',
            'message' => 'Missed or overdue support appointments need follow-up.',
            'metric' => $missedOverdue,
            'metric_label' => 'Overdue',
            'action_label' => 'Open Today tab',
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
                'action_label' => 'Open Today tab',
                'action_target' => '#operations-tab-today',
            ];
        }
    }
@endphp

<section class="operations-critical-alerts mb-3" aria-labelledby="operations-critical-alerts-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h2 id="operations-critical-alerts-heading" class="h6 mb-0 text-uppercase text-muted fw-semibold">Critical Alerts</h2>
        @if ($alerts === [])
            <span class="status-badge status-healthy">All clear</span>
        @else
            <span class="status-badge status-danger">{{ number_format(count($alerts)) }} active</span>
        @endif
    </div>

    @if ($alerts === [])
        <div class="operations-critical-alerts-clear card border-0 shadow-sm operations-card-hover">
            <div class="card-body py-2 px-3 text-muted small mb-0">
                No critical operational alerts right now. Systems are running normally.
            </div>
        </div>
    @else
        <div class="row g-2 operations-critical-alerts-grid">
            @foreach ($alerts as $alert)
                <div class="col-6 col-lg-4 col-xxl-3">
                    <div @class([
                        'card border-0 shadow-sm h-100 operations-critical-alert-card operations-card-hover',
                        'operations-critical-alert-card--danger' => $alert['severity'] === 'danger',
                        'operations-critical-alert-card--warning' => $alert['severity'] === 'warning',
                    ])>
                        <div class="card-body py-2 px-3">
                            <div class="d-flex align-items-start gap-2">
                                <span
                                    @class([
                                        'operations-critical-alert-severity',
                                        'operations-critical-alert-severity--danger' => $alert['severity'] === 'danger',
                                        'operations-critical-alert-severity--warning' => $alert['severity'] === 'warning',
                                    ])
                                    aria-hidden="true"
                                ></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                        <strong class="operations-critical-alert-title">{{ $alert['title'] }}</strong>
                                        @if ($alert['metric'] !== null)
                                            <span class="operations-critical-alert-metric">
                                                <span class="operations-critical-alert-metric-value">{{ number_format($alert['metric']) }}</span>
                                                <span class="operations-critical-alert-metric-label">{{ $alert['metric_label'] }}</span>
                                            </span>
                                        @else
                                            <span @class(['status-badge', 'status-' . $alert['severity']])>{{ $alert['metric_label'] }}</span>
                                        @endif
                                    </div>
                                    <p class="small text-muted mb-2 operations-critical-alert-message">{{ $alert['message'] }}</p>
                                    @if (! empty($alert['action_target']))
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-dark py-0 px-2 operations-critical-alert-action"
                                            data-operations-tab-target="{{ $alert['action_target'] }}"
                                        >
                                            {{ $alert['action_label'] }}
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</section>
