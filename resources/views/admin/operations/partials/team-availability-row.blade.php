@props([
    'member' => [],
    'rowPrefix' => 'member',
    'showUnavailability' => false,
])

@php
    $workCalendar = $member['work_calendar'] ?? [];
    $availability = $member['availability'] ?? [];
    $presence = $member['presence'] ?? [];
    $sessionSummary = $member['session_summary'] ?? [];
    $collapseId = $rowPrefix.'-team-member-details-'.$member['id'];
    $workingTime = $workCalendar['work_hours']
        ?? (($presence['active_duration'] ?? '0m') !== '0m' ? $presence['active_duration'] : null);
    $lastActivity = $presence['last_work_activity_label'] ?? $member['work_activity_label'] ?? null;
    $lastActivityAt = $presence['last_work_activity_at'] ?? $member['work_activity_relative'] ?? null;
    $openWork = (int) ($member['open_work_count'] ?? 0);
    $workloadTone = $openWork >= 6 ? 'danger' : ($openWork >= 3 ? 'warning' : 'healthy');
    $availabilityLabel = $availability['label'] ?? 'Offline';
    $availabilityClass = $availability['badge_class'] ?? 'secondary';
    $statusTone = match ($availabilityClass) {
        'success' => 'healthy',
        'warning' => 'warning',
        'danger' => 'danger',
        default => $showUnavailability ? 'warning' : 'info',
    };
@endphp

<div class="list-group-item px-3 py-3 operations-team-row">
    <div class="operations-team-grid">
        <div class="operations-team-cell operations-team-cell--name">
            <div class="fw-semibold">{{ $member['name'] }}</div>
            @if(filled($member['role_label'] ?? null))
                <div class="text-muted small d-md-none">{{ $member['role_label'] }}</div>
            @endif
            @if($showUnavailability && filled($member['unavailability_label'] ?? null))
                <div class="text-warning-emphasis small">{{ $member['unavailability_label'] }}</div>
            @endif
        </div>

        <div class="operations-team-cell" data-label="Status">
            <span @class(['operations-team-status-pill', 'operations-team-status-pill--' . $statusTone])>
                <span class="operations-team-status-dot" aria-hidden="true"></span>
                <span>{{ $availabilityLabel }}</span>
            </span>
            @if(filled($workCalendar['indicator'] ?? null) && ($workCalendar['status'] ?? '') !== \App\Enums\WorkCalendarDayStatus::LeaveApproved->value)
                <span class="operations-team-status-meta text-muted small">{{ $workCalendar['indicator'] }} {{ $workCalendar['label'] ?? '' }}</span>
            @endif
        </div>

        <div class="operations-team-cell" data-label="Working time">
            <span class="small">{{ $workingTime ?? '—' }}</span>
        </div>

        <div class="operations-team-cell" data-label="Workload">
            <div class="operations-team-workload">
                <span @class(['operations-team-workload-value', 'text-danger' => $workloadTone === 'danger', 'text-warning' => $workloadTone === 'warning'])>{{ number_format($openWork) }}</span>
                <span class="operations-team-workload-bar" aria-hidden="true">
                    <span
                        @class(['operations-team-workload-fill', 'operations-team-workload-fill--' . $workloadTone])
                        style="width: {{ min(100, $openWork * 12) }}%;"
                    ></span>
                </span>
            </div>
        </div>

        <div class="operations-team-cell" data-label="Last activity">
            @if(filled($lastActivity))
                <div class="small">{{ $lastActivity }}</div>
                @if(filled($lastActivityAt))
                    <div class="text-muted small">{{ $lastActivityAt }}</div>
                @endif
            @else
                <span class="text-muted small">No recent activity</span>
            @endif
        </div>

        <div class="operations-team-cell operations-team-cell--actions">
            <button
                type="button"
                class="btn btn-sm btn-link text-muted px-0 operations-team-details-toggle"
                data-bs-toggle="collapse"
                data-bs-target="#{{ $collapseId }}"
                aria-expanded="false"
                aria-controls="{{ $collapseId }}"
            >
                Details
            </button>
        </div>
    </div>

    <div class="collapse mt-2" id="{{ $collapseId }}">
        <div class="text-muted small d-flex flex-column gap-1 operations-team-details">
            @if(filled($member['role_label'] ?? null))
                <span>Role: {{ $member['role_label'] }}</span>
            @endif

            @if($showUnavailability && filled($member['unavailability_label'] ?? null))
                <span>Reason: {{ $member['unavailability_label'] }}</span>
            @endif

            @if(filled($presence['login_at'] ?? null))
                <span>Login: {{ $presence['login_at'] }}</span>
            @endif

            @if(filled($sessionSummary['last_logout_relative'] ?? null))
                <span>Last logout: {{ $sessionSummary['last_logout_relative'] }}</span>
            @endif

            @if(($sessionSummary['manual_logout_count'] ?? 0) > 0)
                <span>Manual logouts today: {{ number_format($sessionSummary['manual_logout_count']) }}</span>
            @endif

            @if(($sessionSummary['timeout_count'] ?? 0) > 0)
                <span>Timeouts today: {{ number_format($sessionSummary['timeout_count']) }}</span>
            @endif

            @if(filled($presence['active_duration'] ?? null) && ($presence['active_duration'] ?? '0m') !== '0m')
                <span>Active: {{ $presence['active_duration'] }}</span>
            @endif

            @if(filled($presence['idle_duration'] ?? null) && ($presence['idle_duration'] ?? '0m') !== '0m')
                <span>Idle: {{ $presence['idle_duration'] }}</span>
            @endif

            @if(($presence['cases_handled_count'] ?? 0) > 0)
                <span>Cases handled: {{ number_format($presence['cases_handled_count']) }}</span>
            @endif

            @if(filled($workCalendar['lunch_time'] ?? null))
                <span>Lunch {{ $workCalendar['lunch_time'] }}</span>
            @endif

            @if(filled($workCalendar['label'] ?? null) && ($workCalendar['status'] ?? '') === \App\Enums\WorkCalendarDayStatus::LeaveApproved->value)
                <span>Approved leave today</span>
            @endif

            @if(filled($member['last_active_relative'] ?? null))
                <span>Last seen: {{ $member['last_active_relative'] }}</span>
            @endif

            @if(filled($presence['indicator'] ?? null))
                <span>{{ $presence['indicator'] }} {{ $presence['label'] ?? '' }}</span>
            @endif
        </div>
    </div>
</div>
