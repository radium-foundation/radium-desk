@props([
    'metrics' => [],
])

<section aria-labelledby="queue-metrics-heading">
    <h2 id="queue-metrics-heading" class="h5 mb-3">Queue Metrics</h2>

    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6">
                    <div class="text-muted small">Pending</div>
                    <div class="fs-4 fw-semibold">{{ number_format($metrics['pending'] ?? 0) }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Running</div>
                    <div class="fs-4 fw-semibold">{{ number_format($metrics['running'] ?? 0) }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Failed</div>
                    <div class="fw-semibold text-danger">{{ number_format($metrics['failed'] ?? 0) }}</div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Retries</div>
                    <div class="fw-semibold text-warning">{{ number_format($metrics['retries'] ?? 0) }}</div>
                </div>
            </div>

            @if(($metrics['queues'] ?? []) !== [])
                <div class="border-top pt-3 mt-3">
                    <div class="text-muted small mb-1">Queues</div>
                    <div class="small">{{ implode(', ', $metrics['queues']) }}</div>
                </div>
            @endif
        </div>
    </div>
</section>
