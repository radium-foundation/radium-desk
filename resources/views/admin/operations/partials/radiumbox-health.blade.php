@props([
    'health' => [],
])

<section aria-labelledby="radiumbox-health-heading">
    <h2 id="radiumbox-health-heading" class="h5 mb-3">RadiumBox Health</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @if(! ($health['enabled'] ?? false))
                <p class="text-muted mb-0">RadiumBox integration is disabled.</p>
            @else
                <div class="row g-3 mb-3">
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Pending Syncs</span>
                            <strong class="operations-metric-value">{{ number_format($health['pending_syncs'] ?? 0) }}</strong>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Failed Syncs</span>
                            <strong class="operations-metric-value">{{ number_format($health['failed_syncs'] ?? 0) }}</strong>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Success Rate (24h)</span>
                            <strong class="operations-metric-value">{{ number_format($health['success_rate_24h'] ?? 0, 1) }}%</strong>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Avg Duration</span>
                            <strong class="operations-metric-value">
                                @if(($health['average_sync_duration_ms'] ?? null) !== null)
                                    {{ number_format($health['average_sync_duration_ms'], 0) }} ms
                                @else
                                    —
                                @endif
                            </strong>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Manual Retries (24h)</span>
                            <strong class="operations-metric-value">{{ number_format($health['manual_retries_24h'] ?? 0) }}</strong>
                        </div>
                    </div>
                    <div class="col-sm-4 col-lg-2">
                        <div class="operations-metric-tile">
                            <span class="operations-metric-label">Last Successful Sync</span>
                            <strong class="operations-metric-value small">
                                @if(! empty($health['last_successful_sync_at']))
                                    {{ display_app_datetime($health['last_successful_sync_at']) }}
                                @else
                                    —
                                @endif
                            </strong>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <h3 class="h6">Pending Orders</h3>
                        @forelse($health['pending_orders'] ?? [] as $order)
                            <div><a href="{{ $order['url'] }}" class="small">{{ $order['order_id'] }}</a></div>
                        @empty
                            <p class="text-muted small mb-0">No pending synchronizations.</p>
                        @endforelse
                    </div>
                    <div class="col-md-6">
                        <h3 class="h6">Failed Orders</h3>
                        <form data-radiumbox-batch-recovery-form
                              data-batch-recovery-url="{{ route('admin.operations.radiumbox.batch-recover') }}">
                            @forelse($health['failed_orders'] ?? [] as $order)
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <input type="checkbox"
                                           name="order_ids[]"
                                           value="{{ $order['id'] }}"
                                           data-radiumbox-batch-order>
                                    <a href="{{ $order['url'] }}" class="small">{{ $order['order_id'] }}</a>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">No failed synchronizations.</p>
                            @endforelse
                            @if(count($health['failed_orders'] ?? []) > 0)
                                <button type="submit" class="btn btn-sm btn-outline-primary mt-2" data-radiumbox-batch-recover-btn>
                                    Retry Selected
                                </button>
                            @endif
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
