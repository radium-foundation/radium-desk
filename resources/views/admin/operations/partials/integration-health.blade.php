@props([
    'cards' => [],
])

@php
    $statusClassMap = [
        'healthy' => 'healthy',
        'warning' => 'warning',
        'failed' => 'danger',
    ];

    $issueCards = collect($cards)->filter(fn (array $card): bool => ($card['status'] ?? 'healthy') !== 'healthy')->values()->all();
@endphp

<section aria-labelledby="integration-health-heading">
    <h2 id="integration-health-heading" class="h5 mb-3">Integration Health</h2>

    @if ($cards === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted small mb-0">No integration health data available.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm operations-card-hover mb-3">
            <div class="card-body py-3">
                <div class="operations-integration-grid" role="list" aria-label="Integration status">
                    @foreach ($cards as $card)
                        @php
                            $status = (string) ($card['status'] ?? 'healthy');
                            $isHealthy = $status === 'healthy';
                        @endphp
                        <div
                            @class([
                                'operations-integration-pill',
                                'operations-integration-pill--' . ($statusClassMap[$status] ?? 'info'),
                                'operations-integration-pill--issue' => ! $isHealthy,
                            ])
                            role="listitem"
                            title="{{ $card['detail'] ?? '' }}"
                        >
                            <span class="operations-integration-pill-icon" aria-hidden="true">{{ $isHealthy ? '✓' : '!' }}</span>
                            <span class="operations-integration-pill-label">{{ $card['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($issueCards !== [])
            <div class="row g-3">
                @foreach ($issueCards as $card)
                    <div class="col-sm-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100 operations-card-hover">
                            <div class="card-body py-3">
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
        @else
            <p class="text-muted small mb-0 operations-health-empty-state">✓ All integrations healthy — expand System tab metrics for detail.</p>
        @endif
    @endif
</section>
