@props([
    'briefing' => null,
    'formatted' => null,
    'members' => [],
    'insights' => [],
])

@php
    $operations = $briefing?->snapshot->operations ?? [];

    $needAction = (int) ($operations['action_required'] ?? collect($members)->sum('open_work_count'));
    $scheduled = (int) ($operations['scheduled'] ?? 0);
    $waitingCustomers = (int) ($operations['waiting'] ?? 0);

    $criticalCount = $formatted?->criticalRiskCount ?? 0;
    $attentionCount = $formatted?->attentionRiskCount ?? 0;
    $monitoringCount = $formatted?->monitoringRiskCount ?? 0;
    $riskCount = $criticalCount + $attentionCount;

    if ($riskCount === 0) {
        $riskCount = (int) ($operations['attention'] ?? 0) + (int) ($operations['overdue'] ?? 0);
    }

    $teamLines = $formatted?->teamPresenceCollecting
        ? [['value' => '—', 'suffix' => 'Collecting']]
        : array_map(
            fn (string $line): array => [
                'value' => (string) (preg_match('/^(\d+)/', $line, $matches) ? $matches[1] : '0'),
                'suffix' => str_contains($line, 'leave') ? 'On Leave' : 'Working',
            ],
            $formatted?->teamLines ?? [],
        );

    $cards = [
        [
            'label' => 'Team',
            'lines' => $teamLines !== [] ? $teamLines : [
                ['value' => '—', 'suffix' => 'Collecting'],
            ],
            'tone' => 'primary',
        ],
        [
            'label' => 'Workload',
            'lines' => [
                ['value' => $needAction, 'suffix' => 'Action Required'],
                ['value' => $scheduled, 'suffix' => 'Scheduled'],
            ],
            'tone' => 'warning',
        ],
        [
            'label' => 'Customer',
            'lines' => [
                ['value' => $waitingCustomers, 'suffix' => 'Waiting'],
            ],
            'tone' => 'info',
        ],
        [
            'label' => 'Risk',
            'lines' => $criticalCount > 0 || $monitoringCount > 0
                ? array_values(array_filter([
                    $criticalCount > 0 ? ['value' => $criticalCount, 'suffix' => 'Require Action'] : null,
                    $monitoringCount > 0 ? ['value' => $monitoringCount, 'suffix' => 'Monitored'] : null,
                ]))
                : [
                    ['value' => $riskCount, 'suffix' => 'Need Attention'],
                ],
            'tone' => $riskCount > 0 || $criticalCount > 0 ? 'danger' : 'success',
        ],
    ];
@endphp

<section class="operations-overview-section mb-4" aria-labelledby="operations-overview-heading">
    <h2 id="operations-overview-heading" class="visually-hidden">Operations overview</h2>

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
