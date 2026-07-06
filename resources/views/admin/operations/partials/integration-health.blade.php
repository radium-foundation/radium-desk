@props([
    'cards' => [],
])

@php
    $statusClassMap = [
        'healthy' => 'healthy',
        'warning' => 'warning',
        'failed' => 'danger',
    ];
@endphp

<section aria-labelledby="integration-health-heading">
    <h2 id="integration-health-heading" class="h5 mb-3">Integration Health</h2>

    <div class="row g-3">
        @foreach($cards as $card)
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm h-100 operations-card-hover">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <h3 class="h6 mb-0">{{ $card['label'] }}</h3>
                            <span @class([
                                'status-badge',
                                'status-' . ($statusClassMap[$card['status'] ?? 'healthy'] ?? 'info'),
                            ])>{{ $card['status_label'] }}</span>
                        </div>
                        <p class="text-muted small mb-2">{{ $card['detail'] }}</p>
                        @if(! empty($card['last_success_at']))
                            <div class="text-muted small">Last success: {{ display_app_datetime_seconds($card['last_success_at']) }}</div>
                        @endif
                        @if(($card['retry_count'] ?? 0) > 0)
                            <div class="text-muted small">Retries (24h): {{ number_format($card['retry_count']) }}</div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
