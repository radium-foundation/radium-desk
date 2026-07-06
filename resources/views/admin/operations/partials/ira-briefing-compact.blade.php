@props([
    'briefing' => null,
    'formatted' => null,
    'reasoningProvider' => 'rule_based',
])

@php
    use App\Enums\AI\AIRiskLevel;

    $recommendations = $briefing?->recommendations ?? [];
    $recommendationCount = count($recommendations);

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

            $label = str($recommendation->message)->limit(72)->toString();

            return [
                'icon' => $icon,
                'label' => $label,
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

    if ($bullets === [] && $briefing !== null) {
        $bullets[] = [
            'icon' => '🟢',
            'label' => 'Operations running normally',
            'severity' => 'success',
        ];
    }

    $chipSeverityMap = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
    ];
@endphp

<section class="operations-ira-command-card mb-3" aria-labelledby="operations-ira-compact-heading">
    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="operations-ira-command-icon" aria-hidden="true">🤖</span>
                        <h2 id="operations-ira-compact-heading" class="h6 mb-0 fw-semibold">Ira</h2>
                        @if ($briefing !== null)
                            <span class="status-badge status-info">{{ str($reasoningProvider)->headline() }}</span>
                        @endif
                    </div>

                    @if ($briefing === null)
                        <div class="operations-skeleton-loader" aria-busy="true" aria-label="Loading recommendations">
                            <div class="operations-skeleton-line operations-skeleton-line--title"></div>
                            <div class="operations-skeleton-line operations-skeleton-line--medium"></div>
                            <p class="visually-hidden">Loading recommendations…</p>
                        </div>
                    @else
                        <p class="operations-ira-command-count mb-2">
                            <strong>{{ number_format(max(1, $recommendationCount)) }}</strong>
                            {{ str('Recommendation')->plural($recommendationCount > 0 ? $recommendationCount : 1) }}
                        </p>

                        <div class="operations-ira-chips d-flex flex-wrap gap-2">
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
        </div>
    </div>
</section>
