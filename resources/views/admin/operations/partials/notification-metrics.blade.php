@props([
    'metrics' => [],
])

<section aria-labelledby="notification-metrics-heading">
    <h2 id="notification-metrics-heading" class="h5 mb-3">Notification Metrics</h2>

    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <div class="text-muted small">Sent Today</div>
                    <div class="fs-4 fw-semibold">{{ number_format($metrics['sent_today'] ?? 0) }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted small">Failed Today</div>
                    <div class="fs-4 fw-semibold text-danger">{{ number_format($metrics['failed_today'] ?? 0) }}</div>
                </div>
                <div class="col-4">
                    <div class="text-muted small">Skipped Today</div>
                    <div class="fs-4 fw-semibold text-secondary">{{ number_format($metrics['skipped_today'] ?? 0) }}</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="text-muted small">Success %</div>
                    <div class="fw-semibold">
                        @if(($metrics['success_rate'] ?? null) !== null)
                            {{ $metrics['success_rate'] }}%
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Avg Delivery Time</div>
                    <div class="fw-semibold">
                        @if(($metrics['average_delivery_ms'] ?? null) !== null)
                            {{ number_format($metrics['average_delivery_ms']) }} ms
                        @else
                            —
                        @endif
                    </div>
                </div>
            </div>

            @php($channelTotals = $metrics['channel_totals'] ?? [])
            @if($channelTotals !== [])
                <div class="border-top pt-3">
                    <div class="text-muted small mb-2">Per Channel</div>
                    @foreach($channelTotals as $channel => $totals)
                        <div class="d-flex justify-content-between small mb-1">
                            <span>{{ ucfirst($channel) }}</span>
                            <span>
                                <span class="text-success">{{ $totals['sent'] ?? 0 }} sent</span>,
                                <span class="text-danger">{{ $totals['failed'] ?? 0 }} failed</span>,
                                <span class="text-secondary">{{ $totals['skipped'] ?? 0 }} skipped</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</section>
