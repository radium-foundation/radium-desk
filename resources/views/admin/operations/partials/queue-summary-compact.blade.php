@props([
    'metrics' => [],
])

@php
    $pending = (int) ($metrics['pending'] ?? 0);
    $running = (int) ($metrics['running'] ?? 0);
    $failed = (int) ($metrics['failed'] ?? 0);
    $retries = (int) ($metrics['retries'] ?? 0);
    $tone = $failed > 0 ? 'danger' : ($pending > 25 || $retries > 0 ? 'warning' : 'success');
    $statusToneMap = [
        'success' => 'healthy',
        'danger' => 'danger',
        'warning' => 'warning',
    ];
@endphp

<section class="operations-queue-summary-compact h-100" aria-labelledby="operations-queue-summary-heading">
    <div class="card border-0 shadow-sm operations-card-hover h-100">
        <div class="card-body py-3 d-flex flex-column">
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                <div>
                    <h2 id="operations-queue-summary-heading" class="h6 mb-0 fw-semibold">Queue Summary</h2>
                    <div class="operations-bento-subtitle text-muted small">Background jobs</div>
                </div>
                <span @class([
                    'status-badge',
                    'status-' . ($statusToneMap[$tone] ?? 'info'),
                ])>
                    {{ $failed > 0 ? 'Failures' : ($pending > 0 ? 'Active' : 'Clear') }}
                </span>
            </div>

            <div class="operations-bento-stat-grid operations-bento-stat-grid--compact mt-auto">
                <div class="operations-bento-stat">
                    <span class="operations-bento-stat-value">{{ number_format($pending) }}</span>
                    <span class="operations-bento-stat-label">Pending</span>
                </div>
                <div class="operations-bento-stat">
                    <span class="operations-bento-stat-value">{{ number_format($running) }}</span>
                    <span class="operations-bento-stat-label">Running</span>
                </div>
                <div class="operations-bento-stat">
                    <span @class(['operations-bento-stat-value', 'text-danger' => $failed > 0])>{{ number_format($failed) }}</span>
                    <span class="operations-bento-stat-label">Failed</span>
                </div>
                <div class="operations-bento-stat">
                    <span @class(['operations-bento-stat-value', 'text-warning' => $retries > 0])>{{ number_format($retries) }}</span>
                    <span class="operations-bento-stat-label">Retries</span>
                </div>
            </div>

            <button
                type="button"
                class="btn btn-sm btn-link px-0 mt-2 align-self-start"
                data-operations-tab-target="#operations-tab-performance"
            >
                View queue analytics
            </button>
        </div>
    </div>
</section>
