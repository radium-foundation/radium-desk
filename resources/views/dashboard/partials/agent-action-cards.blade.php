@props([
    'stats',
    'nextAppointment' => null,
])

@php
    $activeWork = (int) ($stats['my_active_work'] ?? 0);
    $needsAttention = (int) ($stats['my_needs_attention'] ?? 0);
    $breakdown = $stats['my_needs_attention_breakdown'] ?? [];
    $overdueCount = (int) ($breakdown['overdue'] ?? 0);
    $waitingCount = (int) ($breakdown['waiting_follow_ups'] ?? 0);
    $escalationCount = (int) ($breakdown['escalations'] ?? 0);
    $appointment = $nextAppointment ?? ($stats['next_appointment'] ?? null);
    $showBanner = is_array($appointment) && ($appointment['is_imminent'] ?? false);
@endphp

<div class="agent-dashboard-top" role="region" aria-label="Agent dashboard actions">
    @if($showBanner)
        <div class="agent-appointment-banner-sticky-host"
             data-agent-appointment-sticky
             data-incident-id="{{ $appointment['incident_id'] }}">
            @include('dashboard.partials.agent-appointment-banner', ['appointment' => $appointment])
        </div>
    @endif

    <div @class([
        'agent-action-cards',
        'agent-action-cards--with-sticky-banner' => $showBanner,
    ])>
        <a href="{{ route('dashboard', ['queue' => 'my_work']) }}#dashboard-service-cases-panel"
           class="agent-action-card agent-action-card--work dashboard-u-surface-card dashboard-u-transition dashboard-u-hover-lift dashboard-u-focus-ring">
            <div class="agent-action-card__icon" aria-hidden="true">🧰</div>
            <div class="agent-action-card__body">
                <div class="agent-action-card__title">My Work</div>
                <div class="agent-action-card__metric">
                    <span class="agent-action-card__value">{{ number_format($activeWork) }}</span>
                    <span class="agent-action-card__unit">Active {{ str('Case')->plural($activeWork) }}</span>
                </div>
                <div class="agent-action-card__cta">Open →</div>
            </div>
        </a>

        <a href="{{ route('dashboard', ['filter' => 'my_attention']) }}#dashboard-service-cases-panel"
           class="agent-action-card agent-action-card--attention dashboard-u-surface-card dashboard-u-transition dashboard-u-hover-lift dashboard-u-focus-ring">
            <div class="agent-action-card__icon" aria-hidden="true">⚠</div>
            <div class="agent-action-card__body">
                <div class="agent-action-card__title">Needs Attention</div>
                <div class="agent-action-card__metric">
                    <span class="agent-action-card__value">{{ number_format($needsAttention) }}</span>
                    <span class="agent-action-card__unit">{{ str('Case')->plural($needsAttention) }}</span>
                </div>
                @if($needsAttention > 0)
                    <ul class="agent-action-card__breakdown list-unstyled mb-0">
                        @if($overdueCount > 0)
                            <li>{{ number_format($overdueCount) }} Overdue</li>
                        @endif
                        @if($waitingCount > 0)
                            <li>{{ number_format($waitingCount) }} Waiting Follow-ups</li>
                        @endif
                        @if($escalationCount > 0)
                            <li>{{ number_format($escalationCount) }} {{ str('Escalation')->plural($escalationCount) }}</li>
                        @endif
                    </ul>
                @endif
                <div class="agent-action-card__cta">Review →</div>
            </div>
        </a>

        @unless($showBanner)
            @if(is_array($appointment))
                <a href="#"
                   class="agent-action-card agent-action-card--appointment dashboard-u-surface-card dashboard-u-transition dashboard-u-hover-lift dashboard-u-focus-ring"
                   data-agent-open-customer-360="{{ $appointment['incident_id'] }}"
                   data-agent-customer-name="{{ $appointment['customer_name'] }}"
                   data-agent-open-appointment="true">
                    <div class="agent-action-card__icon" aria-hidden="true">📅</div>
                    <div class="agent-action-card__body">
                        <div class="agent-action-card__title">Next Appointment</div>
                        <div class="agent-action-card__metric">
                            <span class="agent-action-card__value agent-action-card__value--time">{{ $appointment['time_label'] }}</span>
                        </div>
                        <p class="agent-action-card__hint mb-0">{{ $appointment['starts_in_label'] }}</p>
                        <p class="agent-action-card__customer mb-0">
                            <span class="agent-action-card__customer-label">Customer:</span>
                            {{ $appointment['customer_name'] }}
                        </p>
                        <div class="agent-action-card__cta">Open Customer360 →</div>
                    </div>
                </a>
            @else
                <div class="agent-action-card agent-action-card--appointment agent-action-card--empty dashboard-u-surface-card">
                    <div class="agent-action-card__icon" aria-hidden="true">📅</div>
                    <div class="agent-action-card__body">
                        <div class="agent-action-card__title">Next Appointment</div>
                        <p class="agent-action-card__empty-text mb-0">No appointments today</p>
                    </div>
                </div>
            @endif
        @endunless
    </div>
</div>
