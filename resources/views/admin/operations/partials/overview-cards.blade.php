@props([
    'dashboard',
    'briefing' => null,
    'formatted' => null,
    'members' => [],
    'insights' => [],
    'intelligence' => [],
])

@php
    $operations = $briefing?->snapshot->operations ?? [];
    $supportToday = $intelligence['today'] ?? [];
    $teamWorkload = $intelligence['team_workload'] ?? [];

    $systemComponents = $dashboard->systemHealth ?? [];
    $integrationCards = $dashboard->integrationHealth ?? [];
    $cashfreeHealth = $dashboard->cashfreeHealth ?? [];
    $radiumBoxHealth = $dashboard->radiumBoxHealth ?? [];
    $teamTelegramStatus = $dashboard->teamTelegramStatus ?? [];

    $criticalCount = 0;
    $warningCount = 0;

    foreach ($systemComponents as $component) {
        $status = (string) ($component['status'] ?? 'healthy');
        if (in_array($status, ['failed', 'critical'], true)) {
            $criticalCount++;
        } elseif ($status === 'warning') {
            $warningCount++;
        }
    }

    foreach ($integrationCards as $card) {
        $status = (string) ($card['status'] ?? 'healthy');
        if ($status === 'failed') {
            $criticalCount++;
        } elseif ($status === 'warning') {
            $warningCount++;
        }
    }

    if (! (bool) ($cashfreeHealth['is_healthy'] ?? true)) {
        $criticalCount++;
    }

    $radiumBoxEnabled = (bool) ($radiumBoxHealth['enabled'] ?? false);
    $radiumBoxIssues = $radiumBoxEnabled && (
        ((int) ($radiumBoxHealth['failed_syncs'] ?? 0)) > 0
        || ((int) ($radiumBoxHealth['pending_syncs'] ?? 0)) > 0
    );
    if ($radiumBoxIssues) {
        $warningCount++;
    }

    $telegramConnected = collect($teamTelegramStatus)->where('connected', true)->count();
    $telegramTotal = count($teamTelegramStatus);
    if ($telegramTotal > 0 && $telegramConnected < $telegramTotal) {
        $warningCount++;
    }

    $overallHealthy = $criticalCount === 0 && $warningCount === 0;
    $overallStatus = $criticalCount > 0 ? 'Critical' : ($warningCount > 0 ? 'Needs Attention' : 'Healthy');
    $overallTone = $criticalCount > 0 ? 'danger' : ($warningCount > 0 ? 'warning' : 'success');

    $activeCases = (int) collect($members)->sum('open_work_count');
    $needsAction = (int) ($operations['action_required'] ?? $activeCases);
    $slaRisk = (int) ($operations['overdue'] ?? 0) + (int) ($operations['warning'] ?? 0);
    if ($slaRisk === 0) {
        $slaRisk = (int) ($supportToday['missed_overdue'] ?? 0);
    }
    $queueMax = max(1, $activeCases, $needsAction, $slaRisk);
    $activePercent = min(100, (int) round(($activeCases / $queueMax) * 100));
    $actionPercent = min(100, (int) round(($needsAction / $queueMax) * 100));
    $slaPercent = min(100, (int) round(($slaRisk / max(1, $needsAction, 1)) * 100));

    $scheduledToday = (int) ($supportToday['scheduled'] ?? 0);
    $completedToday = (int) ($supportToday['completed'] ?? 0);
    $pendingToday = (int) ($supportToday['pending'] ?? 0);
    $overdueToday = (int) ($supportToday['missed_overdue'] ?? 0);
    $supportCompletionPercent = $scheduledToday > 0
        ? min(100, (int) round(($completedToday / $scheduledToday) * 100))
        : 100;

    $activeMembers = collect($teamWorkload)
        ->filter(fn (array $member): bool => ($member['today'] ?? 0) > 0 || ($member['pending'] ?? 0) > 0)
        ->count();
    $busiestMember = collect($teamWorkload)
        ->sortByDesc(fn (array $member): int => (int) ($member['today'] ?? 0) + (int) ($member['pending'] ?? 0))
        ->first();
    $busiestName = $busiestMember['name'] ?? '—';
    $busiestLoad = (int) ($busiestMember['today'] ?? 0) + (int) ($busiestMember['pending'] ?? 0);
    $capacityPercent = min(100, (int) round(($busiestLoad / max(1, 8)) * 100));
    $capacityTone = $capacityPercent >= 85 ? 'danger' : ($capacityPercent >= 60 ? 'warning' : 'success');

    $cards = [
        [
            'icon' => '🚨',
            'label' => 'System Health',
            'tone' => $overallTone,
            'target' => '#operations-health-status',
            'status' => $overallStatus,
            'metrics' => [
                ['label' => 'Critical', 'value' => $criticalCount, 'tone' => $criticalCount > 0 ? 'danger' : 'muted'],
                ['label' => 'Warnings', 'value' => $warningCount, 'tone' => $warningCount > 0 ? 'warning' : 'muted'],
            ],
        ],
        [
            'icon' => '🎫',
            'label' => 'Operations Queue',
            'tone' => $needsAction > 0 || $slaRisk > 0 ? 'warning' : 'success',
            'target' => '#operations-tab-performance',
            'status' => $needsAction > 0 ? 'Action needed' : 'On track',
            'meter' => [
                ['label' => 'Active cases', 'value' => $activeCases, 'percent' => $activePercent, 'tone' => 'primary'],
                ['label' => 'Needs action', 'value' => $needsAction, 'percent' => $actionPercent, 'tone' => $needsAction > 0 ? 'warning' : 'success'],
                ['label' => 'SLA risk', 'value' => $slaRisk, 'percent' => $slaPercent, 'tone' => $slaRisk > 0 ? 'danger' : 'success'],
            ],
        ],
        [
            'icon' => '📅',
            'label' => 'Support Today',
            'tone' => $overdueToday > 0 ? 'danger' : ($pendingToday > 0 ? 'warning' : 'primary'),
            'target' => '#operations-tab-today',
            'status' => $scheduledToday > 0
                ? sprintf('%s%% complete', number_format($supportCompletionPercent))
                : 'No appointments',
            'meter' => [
                ['label' => 'Completion', 'value' => $supportCompletionPercent, 'percent' => $supportCompletionPercent, 'tone' => $supportCompletionPercent >= 80 ? 'success' : 'warning', 'suffix' => '%'],
            ],
            'metrics' => [
                ['label' => 'Scheduled', 'value' => $scheduledToday],
                ['label' => 'Completed', 'value' => $completedToday],
                ['label' => 'Pending', 'value' => $pendingToday],
                ['label' => 'Overdue', 'value' => $overdueToday, 'tone' => $overdueToday > 0 ? 'danger' : 'muted'],
            ],
        ],
        [
            'icon' => '👥',
            'label' => 'Team Load',
            'tone' => $capacityTone,
            'target' => '#operations-tab-team',
            'status' => $activeMembers > 0 ? $busiestName.' busiest' : 'All clear',
            'meter' => [
                ['label' => 'Capacity', 'value' => $capacityPercent, 'percent' => $capacityPercent, 'tone' => $capacityTone, 'suffix' => '%'],
            ],
            'metrics' => [
                ['label' => 'Active members', 'value' => $activeMembers],
                ['label' => 'Busiest load', 'value' => $busiestLoad],
            ],
        ],
    ];
@endphp

<section class="operations-command-center mb-3" aria-labelledby="operations-command-center-heading">
    <h2 id="operations-command-center-heading" class="h6 mb-3 text-uppercase text-muted fw-semibold">Command Center</h2>

    <div class="row g-3 operations-command-grid">
        @foreach ($cards as $card)
            <div class="col-6 col-xl-3">
                <button
                    type="button"
                    class="card border-0 shadow-sm h-100 operations-command-card operations-command-card--{{ $card['tone'] }} w-100 text-start"
                    data-operations-tab-target="{{ $card['target'] }}"
                >
                    <div class="card-body py-3">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <div>
                                <div class="operations-command-card-icon" aria-hidden="true">{{ $card['icon'] }}</div>
                                <div class="operations-command-card-label">{{ $card['label'] }}</div>
                            </div>
                            <span class="badge text-bg-{{ $card['tone'] }} operations-command-card-status">{{ $card['status'] }}</span>
                        </div>

                        @if (! empty($card['meter']))
                            <div class="operations-command-meters mb-2">
                                @foreach ($card['meter'] as $meter)
                                    <div class="operations-command-meter mb-1">
                                        <div class="d-flex justify-content-between small text-muted mb-1">
                                            <span>{{ $meter['label'] }}</span>
                                            <span>{{ number_format($meter['value']) }}{{ $meter['suffix'] ?? '' }}</span>
                                        </div>
                                        <div class="progress operations-command-progress" style="height: 6px;">
                                            <div
                                                class="progress-bar bg-{{ $meter['tone'] }}"
                                                role="progressbar"
                                                style="width: {{ $meter['percent'] }}%;"
                                                aria-valuenow="{{ $meter['percent'] }}"
                                                aria-valuemin="0"
                                                aria-valuemax="100"
                                            ></div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if (! empty($card['metrics']))
                            <div class="operations-command-metrics small">
                                @foreach ($card['metrics'] as $metric)
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="text-muted">{{ $metric['label'] }}</span>
                                        <strong @class([
                                            'text-danger' => ($metric['tone'] ?? null) === 'danger',
                                            'text-warning' => ($metric['tone'] ?? null) === 'warning',
                                        ])>{{ number_format($metric['value']) }}</strong>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </button>
            </div>
        @endforeach
    </div>
</section>
