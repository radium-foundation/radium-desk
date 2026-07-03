@props([
    'slaMetrics' => null,
])

@php
    $stages = $slaMetrics?->stages ?? [];
    $labels = [
        'payment_to_order' => 'Payment → Order',
        'order_to_sync' => 'Order → Sync',
        'sync_to_email' => 'Sync → Email',
        'email_to_booking' => 'Email → Booking',
        'booking_to_resolution' => 'Booking → Resolution',
    ];
@endphp

<section class="customer-360-sla-metrics" aria-labelledby="customer-360-sla-metrics-heading">
    <h4 class="customer-360-section-title" id="customer-360-sla-metrics-heading">SLA Metrics</h4>
    <div class="customer-360-sla-metrics-grid">
        @foreach($labels as $key => $label)
            @php
                $stage = $stages[$key] ?? [];
            @endphp
            <div class="customer-360-sla-metric-card">
                <span class="customer-360-sla-metric-label">{{ $label }}</span>
                <div class="customer-360-sla-metric-values">
                    <span>Median: {{ isset($stage['median_minutes']) ? number_format($stage['median_minutes'], 1).'m' : '—' }}</span>
                    <span>Avg: {{ isset($stage['average_minutes']) ? number_format($stage['average_minutes'], 1).'m' : '—' }}</span>
                    <span>P95: {{ isset($stage['p95_minutes']) ? number_format($stage['p95_minutes'], 1).'m' : '—' }}</span>
                </div>
                <span class="customer-360-sla-metric-sample text-muted small">n={{ $stage['sample_size'] ?? 0 }}</span>
            </div>
        @endforeach
    </div>
</section>
