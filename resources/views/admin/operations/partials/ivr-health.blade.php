@props([
    'health' => [],
])

<section aria-labelledby="ivr-health-heading">
    <h2 id="ivr-health-heading" class="h5 mb-3">Today's IVR Health</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body">
            <div class="operations-metric-row">
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Total Calls</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['total_calls'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Answered</span>
                    <strong class="operations-metric-row-value">
                        {{ number_format($health['answered_count'] ?? 0) }}
                        <span class="text-muted fw-normal">({{ number_format($health['answered_percent'] ?? 0, 1) }}%)</span>
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Missed</span>
                    <strong class="operations-metric-row-value">
                        {{ number_format($health['missed_count'] ?? 0) }}
                        <span class="text-muted fw-normal">({{ number_format($health['missed_percent'] ?? 0, 1) }}%)</span>
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Avg Duration</span>
                    <strong class="operations-metric-row-value">
                        {{ $health['average_duration_label'] ?? '—' }}
                    </strong>
                </div>
            </div>
        </div>
    </div>
</section>
