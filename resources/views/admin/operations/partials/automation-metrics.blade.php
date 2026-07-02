@props([
    'metrics' => [],
])

<section aria-labelledby="automation-metrics-heading">
    <h2 id="automation-metrics-heading" class="h5 mb-3">Automation Metrics</h2>

    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="text-muted small">Executions Today</div>
                    <div class="fs-4 fw-semibold">{{ number_format($metrics['executions_today'] ?? 0) }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Avg Execution Time</div>
                    <div class="fw-semibold">
                        @if(($metrics['average_execution_ms'] ?? null) !== null)
                            {{ number_format($metrics['average_execution_ms']) }} ms
                        @else
                            —
                        @endif
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-4">
                    <div class="text-muted small">Success</div>
                    <div class="fw-semibold text-success">{{ number_format($metrics['success'] ?? 0) }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted small">Partial</div>
                    <div class="fw-semibold text-warning">{{ number_format($metrics['partial_success'] ?? 0) }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted small">Failed</div>
                    <div class="fw-semibold text-danger">{{ number_format($metrics['failed'] ?? 0) }}</div>
                </div>
            </div>
        </div>
    </div>
</section>
