@props([
    'agent',
])

@php
    /** @var \App\Data\TeamActivityAgentRow $agent */
    $latest = $agent->latest;
    $historyId = 'team-activity-history-'.$agent->id;
    $todayLabel = 'Today · '.number_format($agent->todayCount).' '.($agent->todayCount === 1 ? 'activity' : 'activities');
    $hasPresenceMetrics = ! $agent->isVirtual && (
        filled($agent->todayDurationLabel)
        || filled($agent->currentDurationLabel)
        || filled($agent->sessionsToday)
    );
@endphp

<li class="team-activity-row @if($agent->expanded) is-expanded @endif @if($agent->isVirtual) is-virtual @endif"
    data-team-activity-agent="{{ $agent->id }}"
    data-team-activity-expanded="{{ $agent->expanded ? '1' : '0' }}">
    <button type="button"
            class="team-activity-row-summary @if($agent->isVirtual) team-activity-row-summary--virtual @endif"
            data-team-activity-row-toggle
            aria-expanded="{{ $agent->expanded ? 'true' : 'false' }}"
            aria-controls="{{ $historyId }}">
        <span class="team-activity-status-dot" aria-hidden="true"></span>

        <span class="team-activity-name" title="{{ $agent->name }}">{{ $agent->name }}</span>

        <span class="team-activity-status-block">
            <span class="team-activity-status-line">
                <span class="team-activity-status-label">{{ $agent->statusLabel }}</span>
                @if(filled($agent->calendarBadge))
                    <span class="team-activity-badge">{{ $agent->calendarBadge }}</span>
                @endif
            </span>

            @if($hasPresenceMetrics)
                <span class="team-activity-presence-metrics">
                    @if(filled($agent->todayDurationLabel))
                        <span class="team-activity-metric">
                            <span class="team-activity-metric-label">Today</span>
                            <span class="team-activity-metric-value">{{ $agent->todayDurationLabel }}</span>
                        </span>
                    @endif
                    @if(filled($agent->currentDurationLabel))
                        <span class="team-activity-metric">
                            <span class="team-activity-metric-label">Current</span>
                            <span class="team-activity-metric-value">{{ $agent->currentDurationLabel }}</span>
                        </span>
                    @endif
                    @if(filled($agent->sessionsToday))
                        <span class="team-activity-metric">
                            <span class="team-activity-metric-label">Sessions</span>
                            <span class="team-activity-metric-value">{{ number_format($agent->sessionsToday) }}</span>
                        </span>
                    @endif
                </span>
            @elseif(! $agent->isVirtual && filled($agent->workingLabel))
                <span class="team-activity-working-label" title="{{ $agent->workingLabel }}">{{ $agent->workingLabel }}</span>
            @endif
        </span>

        <span class="team-activity-today" title="{{ $todayLabel }}">{{ $todayLabel }}</span>

        <span class="team-activity-latest">
            @if($latest)
                <span class="team-activity-latest-label">
                    {{ $latest->label }}@if(filled($latest->reference)) {{ $latest->reference }}@endif
                </span>
                <time class="team-activity-latest-time"
                      datetime="{{ $latest->at->toIso8601String() }}">{{ display_app_timeline_relative($latest->at) }}</time>
            @else
                <span class="team-activity-latest-label text-muted">—</span>
            @endif
        </span>

        <span class="team-activity-chevron" aria-hidden="true"></span>
    </button>

    <div id="{{ $historyId }}"
         class="team-activity-history"
         data-team-activity-history
         @if(! $agent->expanded) hidden @endif>
        @if($agent->expanded && $agent->history !== [])
            <div class="team-activity-kpi-table-wrap">
                <table class="team-activity-kpi-table">
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Activity</th>
                            <th scope="col">Service Case</th>
                            <th scope="col">Order</th>
                            <th scope="col">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($agent->history as $entry)
                            <tr @if($entry->incidentId)
                                    data-dashboard-activity-entry
                                    data-incident-id="{{ $entry->incidentId }}"
                                @endif>
                                <td>
                                    <time datetime="{{ $entry->at->toIso8601String() }}">{{ $entry->time }}</time>
                                </td>
                                <td>{{ $entry->label }}</td>
                                <td>{{ $entry->serviceCaseReference ?: '—' }}</td>
                                <td>{{ $entry->orderReference ?: '—' }}</td>
                                <td>{{ $entry->description ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif($agent->expanded)
            <p class="team-activity-history-empty text-muted small mb-0">No counted activities today.</p>
        @endif
    </div>
</li>
