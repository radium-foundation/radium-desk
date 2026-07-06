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
@endphp

<section class="operations-ira-command-card mb-3" aria-labelledby="operations-ira-compact-heading">
    <div class="card border-0 shadow-sm">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="operations-ira-command-icon" aria-hidden="true">🤖</span>
                        <h2 id="operations-ira-compact-heading" class="h6 mb-0 fw-semibold">Ira</h2>
                        @if ($briefing !== null)
                            <span class="badge text-bg-light border text-muted">{{ str($reasoningProvider)->headline() }}</span>
                        @endif
                    </div>

                    @if ($briefing === null)
                        <p class="text-muted small mb-0">Loading recommendations…</p>
                    @else
                        <p class="operations-ira-command-count mb-2">
                            <strong>{{ number_format(max(1, $recommendationCount)) }}</strong>
                            {{ str('Recommendation')->plural($recommendationCount > 0 ? $recommendationCount : 1) }}
                        </p>

                        <ul class="list-unstyled mb-0 operations-ira-command-bullets">
                            @foreach ($bullets as $bullet)
                                <li class="operations-ira-command-bullet operations-ira-command-bullet--{{ $bullet['severity'] }}">
                                    <span aria-hidden="true">{{ $bullet['icon'] }}</span>
                                    <span>{{ $bullet['label'] }}</span>
                                </li>
                            @endforeach
                        </ul>
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
