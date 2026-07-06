@props([
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

    $needAction = (int) ($operations['action_required'] ?? collect($members)->sum('open_work_count'));
    $scheduled = (int) ($operations['scheduled'] ?? 0);
    $waitingCustomers = (int) ($operations['waiting'] ?? 0);
    $supportScheduledToday = (int) ($supportToday['scheduled'] ?? 0);
    $supportPendingToday = (int) ($supportToday['pending'] ?? 0);
    $teamMembersWithWork = collect($teamWorkload)
        ->filter(fn (array $member): bool => ($member['today'] ?? 0) > 0 || ($member['pending'] ?? 0) > 0)
        ->count();

    $criticalCount = $formatted?->criticalRiskCount ?? 0;
    $attentionCount = $formatted?->attentionRiskCount ?? 0;
    $monitoringCount = $formatted?->monitoringRiskCount ?? 0;
    $riskCount = $criticalCount + $attentionCount;

    if ($riskCount === 0) {
        $riskCount = (int) ($operations['attention'] ?? 0) + (int) ($operations['overdue'] ?? 0);
    }

    $cards = [
        [
            'label' => 'Cases Needing Action',
            'lines' => [
                ['value' => $needAction, 'suffix' => 'Open work'],
                ['value' => $scheduled, 'suffix' => 'Scheduled'],
            ],
            'tone' => $needAction > 0 ? 'warning' : 'success',
        ],
        [
            'label' => 'Support Today',
            'lines' => [
                ['value' => $supportScheduledToday, 'suffix' => 'Scheduled'],
                ['value' => $supportPendingToday, 'suffix' => 'Pending'],
            ],
            'tone' => $supportPendingToday > 0 ? 'warning' : 'primary',
        ],
        [
            'label' => 'Customer Waiting',
            'lines' => [
                ['value' => $waitingCustomers, 'suffix' => 'Waiting'],
            ],
            'tone' => $waitingCustomers > 0 ? 'info' : 'success',
        ],
        [
            'label' => 'Team Workload',
            'lines' => [
                ['value' => $teamMembersWithWork, 'suffix' => 'Members active'],
                ['value' => collect($teamWorkload)->sum('pending'), 'suffix' => 'Pending cases'],
            ],
            'tone' => 'primary',
        ],
    ];
@endphp

<section class="operations-overview-section mb-4" aria-labelledby="operations-overview-heading">
    <h2 id="operations-overview-heading" class="h6 mb-3 text-uppercase text-muted fw-semibold">Today&apos;s Operations Summary</h2>

    <div class="row g-3 operations-overview-grid">
        @foreach($cards as $card)
            <div class="col-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100 operations-overview-card operations-overview-card--{{ $card['tone'] }}">
                    <div class="card-body py-3">
                        <div class="operations-overview-card-label">{{ $card['label'] }}</div>
                        @foreach($card['lines'] as $line)
                            <div class="operations-overview-card-line">
                                <span class="operations-overview-card-value">{{ is_numeric($line['value']) ? number_format($line['value']) : $line['value'] }}</span>
                                <span class="operations-overview-card-suffix">{{ $line['suffix'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
