@props([
    'briefing' => null,
    'members' => [],
    'insights' => [],
])

@php
    $operations = $briefing?->snapshot->operations ?? [];
    $teamSnapshot = $briefing?->snapshot->team ?? [];

    $activeCount = (int) ($teamSnapshot['available'] ?? collect($members)->filter(
        fn (array $member): bool => ! ($member['availability']['on_leave'] ?? false),
    )->count());

    $leaveCount = (int) ($teamSnapshot['leave'] ?? collect($members)->filter(
        fn (array $member): bool => (bool) ($member['availability']['on_leave'] ?? false),
    )->count());

    $needAction = (int) ($operations['action_required'] ?? collect($members)->sum('open_work_count'));
    $scheduled = (int) ($operations['scheduled'] ?? 0);
    $waitingCustomers = (int) ($operations['waiting'] ?? 0);

    $iraRiskCount = count($briefing?->risks ?? []);
    $advisorRiskCount = collect($insights)->filter(
        fn ($insight): bool => in_array($insight->severity->value, ['high', 'medium'], true),
    )->count();
    $riskCount = $iraRiskCount + $advisorRiskCount;

    if ($riskCount === 0) {
        $riskCount = (int) ($operations['attention'] ?? 0) + (int) ($operations['overdue'] ?? 0);
    }

    $cards = [
        [
            'label' => 'Team',
            'lines' => [
                ['value' => $activeCount, 'suffix' => 'Active'],
                ['value' => $leaveCount, 'suffix' => 'Leave'],
            ],
            'tone' => 'primary',
        ],
        [
            'label' => 'Workload',
            'lines' => [
                ['value' => $needAction, 'suffix' => 'Need Action'],
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
            'lines' => [
                ['value' => $riskCount, 'suffix' => 'Need Attention'],
            ],
            'tone' => $riskCount > 0 ? 'danger' : 'success',
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
                                <span class="operations-overview-card-value">{{ number_format($line['value']) }}</span>
                                <span class="operations-overview-card-suffix">{{ $line['suffix'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
