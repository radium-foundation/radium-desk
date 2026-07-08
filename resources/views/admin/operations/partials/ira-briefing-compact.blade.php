@props([
    'briefing' => null,
    'formatted' => null,
    'reasoningProvider' => 'rule_based',
])

@php
    use App\Enums\AI\AIRiskLevel;

    $recommendations = $briefing?->recommendations ?? [];
    $operations = $briefing?->snapshot->operations ?? [];
    $team = $briefing?->snapshot->team ?? [];

    $insightGroups = [];

    $waiting = (int) ($operations['waiting'] ?? 0);
    if ($waiting > 0) {
        $insightGroups[] = [
            'count' => $waiting,
            'label' => str('customer')->plural($waiting).' waiting',
            'tone' => 'warning',
        ];
    }

    $slaRisks = (int) ($operations['overdue'] ?? 0) + (int) ($operations['warning'] ?? 0);
    if ($slaRisks > 0) {
        $insightGroups[] = [
            'count' => $slaRisks,
            'label' => str('SLA risk')->plural($slaRisks),
            'tone' => 'danger',
        ];
    }

    $overloadedAgents = (int) ($team['overloaded_agents'] ?? 0);
    if ($overloadedAgents === 0) {
        $overloadedAgents = collect($briefing?->risks ?? [])
            ->filter(fn ($risk): bool => $risk->severity === AIRiskLevel::High
                && (str_contains(strtolower($risk->title), 'overload') || str_contains(strtolower($risk->message), 'overload')))
            ->count();
    }
    if ($overloadedAgents > 0) {
        $insightGroups[] = [
            'count' => $overloadedAgents,
            'label' => str('agent')->plural($overloadedAgents).' overloaded',
            'tone' => 'warning',
        ];
    }

    if ($formatted !== null) {
        if (($formatted->criticalRiskCount ?? 0) > 0 && $slaRisks === 0) {
            $insightGroups[] = [
                'count' => $formatted->criticalRiskCount,
                'label' => str('critical risk')->plural($formatted->criticalRiskCount),
                'tone' => 'danger',
            ];
        }

        if (($formatted->attentionRiskCount ?? 0) > 0 && $waiting === 0) {
            $insightGroups[] = [
                'count' => $formatted->attentionRiskCount,
                'label' => str('item')->plural($formatted->attentionRiskCount).' need attention',
                'tone' => 'warning',
            ];
        }
    }

    $bullets = collect($recommendations)
        ->take(3)
        ->map(function ($recommendation) use ($briefing): array {
            $severity = 'success';
            $icon = '🟢';

            foreach ($briefing?->risks ?? [] as $risk) {
                if (str_contains($recommendation->key, 'sla') && $risk->severity === AIRiskLevel::High) {
                    $severity = 'danger';
                    $icon = '🔴';
                    break;
                }

                if (str_contains($recommendation->key, 'team') || str_contains($recommendation->key, 'workload')) {
                    $severity = 'warning';
                    $icon = '🟡';
                }
            }

            if (str_contains($recommendation->key, 'sla')) {
                $severity = 'danger';
                $icon = '🔴';
            } elseif (str_contains($recommendation->key, 'team') || str_contains($recommendation->key, 'workload') || str_contains($recommendation->key, 'overload')) {
                $severity = 'warning';
                $icon = '🟡';
            } elseif (str_contains($recommendation->key, 'payment') || str_contains($recommendation->key, 'cashfree')) {
                $severity = 'success';
                $icon = '🟢';
            }

            return [
                'icon' => $icon,
                'label' => str($recommendation->message)->limit(72)->toString(),
                'severity' => $severity,
            ];
        })
        ->values()
        ->all();

    if ($bullets === [] && $formatted !== null) {
        foreach (array_slice($formatted->attentionLines, 0, 3) as $line) {
            $bullets[] = [
                'icon' => '🟡',
                'label' => str($line)->limit(72)->toString(),
                'severity' => 'warning',
            ];
        }
    }

    if ($insightGroups === [] && $bullets === [] && $briefing !== null) {
        $insightGroups[] = [
            'count' => null,
            'label' => 'Operations running normally',
            'tone' => 'healthy',
        ];
    }

    $chipSeverityMap = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
        'healthy' => 'healthy',
    ];
@endphp

<section class="operations-ira-command-card h-100" aria-labelledby="operations-ira-compact-heading">
    <div class="card border-0 shadow-sm operations-card-hover h-100">
        <div class="card-body py-3 d-flex flex-column">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="operations-ira-command-icon" aria-hidden="true">🤖</span>
                    <h2 id="operations-ira-compact-heading" class="h6 mb-0 fw-semibold">Ira Insight</h2>
                    @if ($briefing !== null)
                        <span class="status-badge status-info">{{ str($reasoningProvider)->headline() }}</span>
                    @endif
                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#operations-ira-full-analysis-modal"
                    @if ($briefing === null) disabled @endif
                >
                    View Full Analysis
                </button>
            </div>

            @if ($briefing === null)
                <div class="operations-skeleton-loader flex-grow-1" aria-busy="true" aria-label="Loading recommendations">
                    <div class="operations-skeleton-line operations-skeleton-line--title"></div>
                    <div class="operations-skeleton-line operations-skeleton-line--medium"></div>
                    <p class="visually-hidden">Loading recommendations…</p>
                </div>
            @else
                @if ($insightGroups !== [])
                    <p class="operations-ira-noticed-label small text-muted mb-2">Ira noticed:</p>
                    <div class="operations-ira-insight-groups d-flex flex-wrap gap-2 mb-2">
                        @foreach ($insightGroups as $group)
                            <span @class([
                                'operations-ira-insight-group',
                                'operations-ira-insight-group--' . ($chipSeverityMap[$group['tone']] ?? 'info'),
                            ])>
                                @if ($group['count'] !== null)
                                    <strong class="operations-ira-insight-group-count">{{ number_format($group['count']) }}</strong>
                                @endif
                                <span class="operations-ira-insight-group-label">{{ $group['label'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif

                @if ($bullets !== [])
                    <div class="operations-ira-chips d-flex flex-wrap gap-2 mt-auto">
                        @foreach ($bullets as $bullet)
                            <span @class([
                                'operations-ira-chip',
                                'operations-ira-chip--' . ($chipSeverityMap[$bullet['severity']] ?? 'info'),
                            ])>
                                <span class="operations-ira-chip-icon" aria-hidden="true">{{ $bullet['icon'] }}</span>
                                <span class="operations-ira-chip-label">{{ $bullet['label'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>
</section>
