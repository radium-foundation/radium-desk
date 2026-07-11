@props([
    'viewModel' => [],
])

@php
    use App\Support\AppDateFormatter;

    $status = $viewModel['status'] ?? ['status' => 'healthy', 'label' => 'Healthy'];
    $statusKey = $status['status'] ?? 'healthy';
    $statusLabel = $status['label'] ?? 'Healthy';
    $lastContact = $viewModel['last_contact'] ?? null;
    $preferredChannel = $viewModel['preferred_channel'] ?? null;
    $displayChannel = filled($preferredChannel) ? $preferredChannel : 'Not available';
@endphp

<section {{ $attributes->merge(['class' => 'c360-health-card']) }}
         data-customer-360-section="customer-health-card"
         aria-labelledby="c360-health-card-heading">
    <div class="c360-health-card-header">
        <div class="c360-health-card-heading-wrap">
            <h3 class="c360-health-card-heading" id="c360-health-card-heading">Customer health</h3>
            <p class="c360-health-card-subtitle mb-0">Snapshot of customer engagement and service activity.</p>
        </div>
        <span @class([
            'c360-health-card-status',
            'c360-health-card-status--healthy' => $statusKey === 'healthy',
            'c360-health-card-status--attention' => $statusKey === 'attention',
            'c360-health-card-status--critical' => $statusKey === 'critical',
        ])>
            {{ $statusLabel }}
        </span>
    </div>

    <dl class="c360-health-card-grid">
        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-bag-check" aria-hidden="true"></i>
                Total Orders
            </dt>
            <dd class="c360-health-card-metric-value">{{ number_format((int) ($viewModel['total_orders'] ?? 0)) }}</dd>
        </div>

        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-tools" aria-hidden="true"></i>
                Active Service Cases
            </dt>
            <dd class="c360-health-card-metric-value">{{ number_format((int) ($viewModel['active_service_cases'] ?? 0)) }}</dd>
        </div>

        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
                Completed Service Cases
            </dt>
            <dd class="c360-health-card-metric-value">{{ number_format((int) ($viewModel['completed_service_cases'] ?? 0)) }}</dd>
        </div>

        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-calendar-event" aria-hidden="true"></i>
                Total Appointments
            </dt>
            <dd class="c360-health-card-metric-value">{{ number_format((int) ($viewModel['total_appointments'] ?? 0)) }}</dd>
        </div>

        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-calendar-x" aria-hidden="true"></i>
                Missed Appointments
            </dt>
            <dd @class([
                'c360-health-card-metric-value',
                'c360-health-card-metric-value--alert' => (int) ($viewModel['missed_appointments'] ?? 0) > 0,
            ])>
                {{ number_format((int) ($viewModel['missed_appointments'] ?? 0)) }}
            </dd>
        </div>

        <div class="c360-health-card-metric">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-chat-dots" aria-hidden="true"></i>
                Preferred Communication Channel
            </dt>
            <dd class="c360-health-card-metric-value">{{ $displayChannel }}</dd>
        </div>

        <div class="c360-health-card-metric c360-health-card-metric--wide">
            <dt class="c360-health-card-metric-label">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                Last Customer Contact
            </dt>
            <dd class="c360-health-card-metric-value">
                @if(is_array($lastContact) && ($lastContact['occurred_at'] ?? null) !== null)
                    <span class="c360-health-card-contact-channel">{{ $lastContact['label'] ?? 'Contact' }}</span>
                    <time class="c360-health-card-contact-time"
                          datetime="{{ $lastContact['occurred_at']->toIso8601String() }}"
                          title="{{ AppDateFormatter::timelineDatetime($lastContact['occurred_at']) }}">
                        {{ $lastContact['occurred_at_label'] ?? AppDateFormatter::timelineRelative($lastContact['occurred_at']) }}
                    </time>
                @else
                    <span class="c360-health-card-metric-placeholder">Not available</span>
                @endif
            </dd>
        </div>
    </dl>
</section>
