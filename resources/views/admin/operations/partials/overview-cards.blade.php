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
    $operational = $intelligence['operational'] ?? [];
    $supportToday = $intelligence['today'] ?? [];
    $teamWorkload = $intelligence['team_workload'] ?? [];
    $ivrHealth = $dashboard->ivrAnalytics['ivr_health'] ?? [];

    if ($operations === [] && $operational !== []) {
        $operations = [
            'action_required' => $operational['action_required'] ?? 0,
            'waiting' => $operational['waiting'] ?? 0,
            'overdue' => $operational['service_overdue'] ?? 0,
            'warning' => $operational['service_warning'] ?? 0,
            'hardware_overdue' => $operational['hardware_overdue'] ?? 0,
            'hardware_warning' => $operational['hardware_warning'] ?? 0,
            'missed_appointments' => $operational['missed_appointments'] ?? ($supportToday['missed_overdue'] ?? 0),
        ];
    }

    $needsAction = (int) ($operations['action_required'] ?? collect($members)->sum('open_work_count'));
    $slaRiskCases = (int) ($operations['overdue'] ?? 0) + (int) ($operations['warning'] ?? 0);
    $missedAppointments = (int) ($operations['missed_appointments'] ?? $supportToday['missed_overdue'] ?? 0);
    $hardwareSlaRisk = (int) ($operations['hardware_overdue'] ?? 0) + (int) ($operations['hardware_warning'] ?? 0);
    $opsTone = $needsAction > 0 || $slaRiskCases > 0 || $missedAppointments > 0 ? 'warning' : 'success';

    $scheduledToday = (int) ($supportToday['scheduled'] ?? 0);
    $completedToday = (int) ($supportToday['completed'] ?? 0);
    $pendingToday = (int) ($supportToday['pending'] ?? 0);
    $overdueToday = (int) ($supportToday['missed_overdue'] ?? 0);
    $supportCompletionPercent = $scheduledToday > 0
        ? min(100, (int) round(($completedToday / $scheduledToday) * 100))
        : 100;

    $activeMembers = collect($teamWorkload)
        ->filter(fn (array $member): bool => ($member['today'] ?? 0) > 0
            || ($member['action_needed'] ?? $member['active_cases'] ?? 0) > 0
            || ($member['scheduled_today'] ?? 0) > 0)
        ->count();
    $busiestMember = collect($teamWorkload)
        ->sortByDesc(fn (array $member): int => (int) ($member['active_cases'] ?? 0))
        ->first();
    $busiestName = $busiestMember['name'] ?? '—';
    $busiestLoad = (int) ($busiestMember['active_cases'] ?? 0);
    $capacityPercent = min(100, (int) round(($busiestLoad / max(1, 8)) * 100));
    $capacityTone = $capacityPercent >= 85 ? 'danger' : ($capacityPercent >= 60 ? 'warning' : 'success');

    $totalCalls = (int) ($ivrHealth['total_calls'] ?? 0);
    $answeredPercent = (float) ($ivrHealth['answered_percent'] ?? 0);
    $missedPercent = (float) ($ivrHealth['missed_percent'] ?? 0);
    $ivrTone = $missedPercent >= 20 ? 'danger' : ($missedPercent >= 10 ? 'warning' : 'success');

    $statusToneMap = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
        'primary' => 'info',
    ];
@endphp

<section class="operations-command-bento-cards" aria-labelledby="operations-command-center-heading">
    <h2 id="operations-command-center-heading" class="h6 mb-2 text-uppercase text-muted fw-semibold">Command Center</h2>

    <div class="operations-bento-inner">
        <button
            type="button"
            class="card border-0 shadow-sm operations-command-card operations-bento-card operations-bento-card--lg operations-command-card--{{ $opsTone }} operations-card-hover"
            data-operations-tab-target="#operations-tab-today"
        >
            <div class="card-body py-3">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div>
                        <div class="operations-command-card-label">Today's Operations Health</div>
                        <div class="operations-bento-subtitle text-muted small">Support Today · Operations Queue</div>
                        <div class="operations-bento-headline">
                            @if ($scheduledToday > 0)
                                {{ number_format($supportCompletionPercent) }}% complete
                            @else
                                {{ number_format($needsAction) }} active cases
                            @endif
                        </div>
                    </div>
                    <span @class([
                        'status-badge',
                        'status-' . ($statusToneMap[$opsTone] ?? 'info'),
                        'operations-command-card-status',
                    ])>
                        {{ $needsAction > 0 ? 'Action needed' : 'On track' }}
                    </span>
                </div>

                <div class="operations-bento-stat-grid">
                    <div class="operations-bento-stat">
                        <span class="operations-bento-stat-value">{{ number_format($needsAction) }}</span>
                        <span class="operations-bento-stat-label">Needs action</span>
                    </div>
                    <div class="operations-bento-stat">
                        <span @class(['operations-bento-stat-value', 'text-danger' => $slaRiskCases > 0])>{{ number_format($slaRiskCases) }}</span>
                        <span class="operations-bento-stat-label">SLA risks</span>
                    </div>
                    <div class="operations-bento-stat">
                        <span class="operations-bento-stat-value">{{ number_format($scheduledToday) }}</span>
                        <span class="operations-bento-stat-label">Scheduled</span>
                    </div>
                    <div class="operations-bento-stat">
                        <span @class(['operations-bento-stat-value', 'text-danger' => $overdueToday > 0])>{{ number_format($overdueToday) }}</span>
                        <span class="operations-bento-stat-label">Missed</span>
                    </div>
                </div>

                @if ($hardwareSlaRisk > 0)
                    <div class="operations-bento-footnote small text-muted mt-2">
                        {{ number_format($hardwareSlaRisk) }} hardware SLA risk{{ $hardwareSlaRisk === 1 ? '' : 's' }}
                    </div>
                @endif
            </div>
        </button>

        <button
            type="button"
            class="card border-0 shadow-sm operations-command-card operations-bento-card operations-bento-card--md operations-command-card--{{ $ivrTone }} operations-card-hover"
            data-operations-tab-target="#operations-tab-performance"
        >
            <div class="card-body py-3">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div>
                        <div class="operations-command-card-label">IVR Health</div>
                        <div class="operations-bento-headline">{{ number_format($totalCalls) }} calls today</div>
                    </div>
                    <span @class([
                        'status-badge',
                        'status-' . ($statusToneMap[$ivrTone] ?? 'info'),
                        'operations-command-card-status',
                    ])>
                        {{ number_format($answeredPercent, 0) }}% answered
                    </span>
                </div>

                <div class="operations-bento-stat-grid operations-bento-stat-grid--compact">
                    <div class="operations-bento-stat">
                        <span class="operations-bento-stat-value text-success">{{ number_format($answeredPercent, 1) }}%</span>
                        <span class="operations-bento-stat-label">Answered</span>
                    </div>
                    <div class="operations-bento-stat">
                        <span @class(['operations-bento-stat-value', 'text-danger' => $missedPercent >= 10])>{{ number_format($missedPercent, 1) }}%</span>
                        <span class="operations-bento-stat-label">Missed</span>
                    </div>
                </div>
            </div>
        </button>

        <button
            type="button"
            class="card border-0 shadow-sm operations-command-card operations-bento-card operations-bento-card--sm operations-command-card--{{ $capacityTone }} operations-card-hover"
            data-operations-tab-target="#operations-tab-team"
        >
            <div class="card-body py-3">
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                    <div>
                        <div class="operations-command-card-label">Team Load</div>
                        <div class="operations-bento-headline">{{ $activeMembers > 0 ? $busiestName : 'All clear' }}</div>
                    </div>
                    <span @class([
                        'status-badge',
                        'status-' . ($statusToneMap[$capacityTone] ?? 'info'),
                        'operations-command-card-status',
                    ])>
                        {{ $activeMembers > 0 ? 'Busiest' : 'Clear' }}
                    </span>
                </div>

                <div class="operations-bento-stat-grid operations-bento-stat-grid--compact">
                    <div class="operations-bento-stat">
                        <span class="operations-bento-stat-value">{{ number_format($activeMembers) }}</span>
                        <span class="operations-bento-stat-label">Active</span>
                    </div>
                    <div class="operations-bento-stat">
                        <span class="operations-bento-stat-value">{{ number_format($busiestLoad) }}</span>
                        <span class="operations-bento-stat-label">Peak load</span>
                    </div>
                </div>

                <div class="operations-command-meter mt-2">
                    <div class="progress operations-command-progress">
                        <div
                            class="progress-bar bg-{{ $capacityTone }}"
                            role="progressbar"
                            style="width: {{ $capacityPercent }}%;"
                            aria-valuenow="{{ $capacityPercent }}"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        ></div>
                    </div>
                </div>
            </div>
        </button>
    </div>
</section>
