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
        'agent-kpi-grid',
        'agent-kpi-grid--two-up' => $showBanner,
    ])>
        <a href="{{ route('dashboard', ['queue' => 'my_work']) }}#dashboard-service-cases-panel"
           class="agent-kpi-tile agent-kpi-tile--work">
            <span class="agent-kpi-tile__title">Assigned Cases</span>
            <span class="agent-kpi-tile__value">{{ number_format($activeWork) }}</span>
            <span class="agent-kpi-tile__meta">Active {{ str('Case')->plural($activeWork) }}</span>
        </a>

        <a href="{{ route('dashboard', ['filter' => 'my_attention']) }}#dashboard-service-cases-panel"
           class="agent-kpi-tile agent-kpi-tile--attention">
            <span class="agent-kpi-tile__title">Action Required</span>
            <span class="agent-kpi-tile__value">{{ number_format($needsAttention) }}</span>
            @if($overdueCount > 0 || $waitingCount > 0 || $escalationCount > 0)
                <span class="agent-kpi-tile__chips" role="list" aria-label="Action required breakdown">
                    @if($overdueCount > 0)
                        <span class="agent-kpi-chip agent-kpi-chip--overdue" role="listitem" aria-label="{{ number_format($overdueCount) }} overdue">
                            <span aria-hidden="true">🔴</span>
                            <span>{{ number_format($overdueCount) }}</span>
                        </span>
                    @endif
                    @if($waitingCount > 0)
                        <span class="agent-kpi-chip agent-kpi-chip--waiting" role="listitem" aria-label="{{ number_format($waitingCount) }} waiting">
                            <span aria-hidden="true">🟡</span>
                            <span>{{ number_format($waitingCount) }}</span>
                        </span>
                    @endif
                    @if($escalationCount > 0)
                        <span class="agent-kpi-chip agent-kpi-chip--escalation" role="listitem" aria-label="{{ number_format($escalationCount) }} {{ str('escalation')->plural($escalationCount) }}">
                            <span aria-hidden="true">🟣</span>
                            <span>{{ number_format($escalationCount) }}</span>
                        </span>
                    @endif
                </span>
            @endif
        </a>

        @unless($showBanner)
            @if(is_array($appointment))
                <button type="button"
                        class="agent-kpi-tile agent-kpi-tile--appointment"
                        data-agent-open-customer-360="{{ $appointment['incident_id'] }}"
                        data-agent-customer-name="{{ $appointment['customer_name'] }}"
                        data-agent-open-appointment="true">
                    <span class="agent-kpi-tile__title">Next Appointment</span>
                    <span class="agent-kpi-tile__value agent-kpi-tile__value--time">{{ $appointment['time_label'] }}</span>
                    <span class="agent-kpi-tile__meta">{{ $appointment['starts_in_label'] }}</span>
                    <span class="agent-kpi-tile__detail">{{ $appointment['customer_name'] }}</span>
                    @if(filled($appointment['device_model'] ?? null))
                        <span class="agent-kpi-tile__detail">{{ $appointment['device_model'] }}</span>
                    @endif
                </button>
            @else
                <div class="agent-kpi-tile agent-kpi-tile--appointment agent-kpi-tile--empty agent-kpi-tile--static"
                     role="status"
                     aria-label="No appointments today">
                    <span class="agent-kpi-tile__empty-icon" aria-hidden="true">📅</span>
                    <span class="agent-kpi-tile__title">No Appointments Today</span>
                </div>
            @endif
        @endunless
    </div>
</div>
