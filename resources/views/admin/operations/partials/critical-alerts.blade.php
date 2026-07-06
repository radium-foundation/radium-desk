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
            'message' => sprintf('%s paid payment(s) need order recovery.', number_format($paidMissing)),
            'action_label' => 'Review Cashfree health',
            'action_target' => '#operations-health-trigger-cashfree',
        ];
    }

    $activeWebhookFailures = (int) ($cashfreeHealth['active_failed_webhooks'] ?? 0);
    if ($activeWebhookFailures > 0) {
        $alerts[] = [
            'severity' => 'danger',
            'title' => 'Cashfree webhook failures',
            'message' => sprintf('%s actionable webhook failure(s) require recovery.', number_format($activeWebhookFailures)),
            'action_label' => 'Review Cashfree health',
            'action_target' => '#operations-health-trigger-cashfree',
        ];
    }

    $failedSyncs = (int) ($radiumBoxHealth['failed_syncs'] ?? 0);
    if (($radiumBoxHealth['enabled'] ?? false) && $failedSyncs > 0) {
        $alerts[] = [
            'severity' => 'danger',
            'title' => 'RadiumBox sync failures',
            'message' => sprintf('%s order sync(s) failed and need attention.', number_format($failedSyncs)),
            'action_label' => 'Review RadiumBox health',
            'action_target' => '#operations-health-trigger-radiumbox',
        ];
    }

    $missedOverdue = (int) ($supportToday['missed_overdue'] ?? 0);
    if ($missedOverdue > 0) {
        $alerts[] = [
            'severity' => 'warning',
            'title' => 'Overdue support appointments',
            'message' => sprintf('%s missed or overdue support appointment(s).', number_format($missedOverdue)),
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
            <span class="badge text-bg-success">All clear</span>
        @else
            <span class="badge text-bg-danger">{{ number_format(count($alerts)) }} active</span>
        @endif
    </div>

    @if ($alerts === [])
        <div class="operations-critical-alerts-clear card border-0 shadow-sm">
            <div class="card-body py-2 px-3 text-muted small mb-0">
                No critical operational alerts right now. Systems are running normally.
            </div>
        </div>
    @else
        <div class="d-flex flex-column gap-2">
            @foreach ($alerts as $alert)
                <div @class([
                    'alert mb-0 py-2 px-3 d-flex flex-column flex-md-row justify-content-between align-items-start gap-2',
                    'alert-danger' => $alert['severity'] === 'danger',
                    'alert-warning' => $alert['severity'] === 'warning',
                ])>
                    <div>
                        <strong>{{ $alert['title'] }}</strong>
                        <div class="small">{{ $alert['message'] }}</div>
                    </div>
                    @if (! empty($alert['action_target']))
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-dark"
                            data-operations-tab-target="{{ $alert['action_target'] }}"
                        >
                            {{ $alert['action_label'] }}
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
