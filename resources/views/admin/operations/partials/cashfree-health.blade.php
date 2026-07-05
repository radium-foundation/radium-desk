@props([
    'health' => [],
])

<section aria-labelledby="cashfree-health-heading">
    <h2 id="cashfree-health-heading" class="h5 mb-3">Cashfree Health</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <p class="text-muted small mb-0">{{ $health['detail'] ?? 'Payment webhook integration.' }}</p>
                <span class="badge bg-{{ $health['badge_class'] ?? 'secondary' }}">{{ $health['status_label'] ?? 'Unknown' }}</span>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Paid Missing Orders</span>
                        <strong class="operations-metric-value">{{ number_format($health['paid_without_desk_order'] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Active Failures</span>
                        <strong class="operations-metric-value">{{ number_format($health['active_failed_webhooks'] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Resolved Failures</span>
                        <strong class="operations-metric-value">{{ number_format($health['historical_resolved_failures'] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Invalid Events</span>
                        <strong class="operations-metric-value">{{ number_format($health['invalid_event_failures'] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Audit Failures</span>
                        <strong class="operations-metric-value">{{ number_format($health['total_failed_webhooks'] ?? 0) }}</strong>
                    </div>
                </div>
                <div class="col-sm-4 col-lg-2">
                    <div class="operations-metric-tile">
                        <span class="operations-metric-label">Last Success</span>
                        <strong class="operations-metric-value small">
                            @if(! empty($health['last_successful_webhook_at']))
                                {{ display_app_datetime($health['last_successful_webhook_at']) }}
                            @else
                                —
                            @endif
                        </strong>
                    </div>
                </div>
            </div>

            @if(! empty($health['affected_order_ids']))
                <div>
                    <h3 class="h6">Affected Order IDs</h3>
                    <p class="text-muted small mb-0">{{ implode(', ', $health['affected_order_ids']) }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
