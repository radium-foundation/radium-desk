@props([
    'health' => [],
])

<section aria-labelledby="cashfree-health-heading">
    <h2 id="cashfree-health-heading" class="h5 mb-3">Cashfree Health</h2>

    <div class="card border-0 shadow-sm operations-card-hover">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <p class="text-muted small mb-0">{{ $health['detail'] ?? 'Payment webhook integration.' }}</p>
                @php
                    $statusClass = match ($health['badge_class'] ?? 'secondary') {
                        'success' => 'healthy',
                        'danger' => 'danger',
                        'warning' => 'warning',
                        default => 'info',
                    };
                @endphp
                <span @class(['status-badge', 'status-' . $statusClass])>{{ $health['status_label'] ?? 'Unknown' }}</span>
            </div>

            <div class="operations-metric-row mb-3">
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Webhook Secret</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        {{ $health['webhook_secret_status_label'] ?? '—' }}
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">System User</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        {{ $health['system_user_status_label'] ?? '—' }}
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Queue</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        {{ number_format($health['queue_pending'] ?? 0) }} pending
                        / {{ number_format($health['queue_failed'] ?? 0) }} failed
                    </strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Outbox</span>
                    <strong class="operations-metric-row-value operations-metric-row-value--compact">
                        {{ number_format($health['outbox_pending'] ?? 0) }} pending
                        / {{ number_format($health['outbox_failed'] ?? 0) }} failed
                    </strong>
                </div>
            </div>

            <dl class="row small mb-3">
                <dt class="col-sm-4 text-muted">Configured email</dt>
                <dd class="col-sm-8 mb-1">
                    {{ $health['system_user_email'] !== '' ? $health['system_user_email'] : '—' }}
                    @if(! empty($health['system_user_role_label']))
                        <span class="text-muted">({{ $health['system_user_role_label'] }})</span>
                    @endif
                </dd>
                <dt class="col-sm-4 text-muted">Latest webhook</dt>
                <dd class="col-sm-8 mb-1">
                    @if(! empty($health['latest_webhook_at']))
                        {{ display_app_datetime($health['latest_webhook_at']) }}
                    @else
                        —
                    @endif
                </dd>
                <dt class="col-sm-4 text-muted">Last successful payment</dt>
                <dd class="col-sm-8 mb-1">
                    @if(! empty($health['last_successful_webhook_at']))
                        {{ display_app_datetime($health['last_successful_webhook_at']) }}
                    @else
                        —
                    @endif
                </dd>
                <dt class="col-sm-4 text-muted">Last failed payment</dt>
                <dd class="col-sm-8 mb-0">
                    @if(! empty($health['last_failed_webhook_at']))
                        {{ display_app_datetime($health['last_failed_webhook_at']) }}
                    @else
                        —
                    @endif
                </dd>
            </dl>

            <div class="operations-metric-row mb-3">
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Paid Missing</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['paid_without_desk_order'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Failed</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['active_failed_webhooks'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Resolved</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['historical_resolved_failures'] ?? 0) }}</strong>
                </div>
                <div class="operations-metric-row-item">
                    <span class="operations-metric-row-label">Invalid Events</span>
                    <strong class="operations-metric-row-value">{{ number_format($health['invalid_event_failures'] ?? 0) }}</strong>
                </div>
            </div>

            @if(! empty($health['affected_order_ids']))
                <div>
                    <h3 class="h6">Affected Order IDs</h3>
                    <p class="text-muted small mb-0">{{ implode(', ', $health['affected_order_ids']) }}</p>
                </div>
            @else
                <p class="text-muted small mb-0 operations-health-empty-state">✓ All systems operational</p>
            @endif
        </div>
    </div>
</section>
