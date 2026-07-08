@props([
    'health' => [],
])

@php
    $totalCalls = (int) ($health['total_calls'] ?? 0);
    $answeredCount = (int) ($health['answered_count'] ?? 0);
    $missedCount = (int) ($health['missed_count'] ?? 0);
    $answeredPercent = (float) ($health['answered_percent'] ?? 0);
    $missedPercent = (float) ($health['missed_percent'] ?? 0);
    $agentCount = count($health['agents'] ?? []);
@endphp

<section aria-labelledby="ivr-health-heading">
    <h2 id="ivr-health-heading" class="h5 mb-3">Today's IVR</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body py-3">
            @if ($totalCalls === 0)
                <p class="text-muted small mb-0">No IVR calls recorded today. Agent performance will appear once calls start.</p>
            @else
                <div class="operations-ivr-metrics">
                    <div class="operations-ivr-metric operations-ivr-metric--primary">
                        <span class="operations-ivr-metric-value">{{ number_format($totalCalls) }}</span>
                        <span class="operations-ivr-metric-label">Total calls</span>
                    </div>
                    <div class="operations-ivr-metric operations-ivr-metric--success">
                        <span class="operations-ivr-metric-value">{{ number_format($answeredPercent, 1) }}%</span>
                        <span class="operations-ivr-metric-label">Answered ({{ number_format($answeredCount) }})</span>
                    </div>
                    <div @class(['operations-ivr-metric', 'operations-ivr-metric--danger' => $missedPercent >= 10])>
                        <span class="operations-ivr-metric-value">{{ number_format($missedPercent, 1) }}%</span>
                        <span class="operations-ivr-metric-label">Missed ({{ number_format($missedCount) }})</span>
                    </div>
                    <div class="operations-ivr-metric">
                        <span class="operations-ivr-metric-value operations-ivr-metric-value--sm">{{ $health['average_duration_label'] ?? '—' }}</span>
                        <span class="operations-ivr-metric-label">Avg duration</span>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
