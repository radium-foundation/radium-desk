@props([
    'agent',
])

@php
    /** @var \App\Data\TeamActivityAgentRow $agent */
    $latest = $agent->latest;
    $historyId = 'team-activity-history-'.$agent->id;
    $activityCountLabel = $agent->todayCount === 1 ? 'Activity Today' : 'Activities Today';
    $hasPresenceMetrics = ! $agent->isVirtual && (
        filled($agent->todayDurationLabel)
        || filled($agent->currentDurationLabel)
        || filled($agent->sessionsToday)
    );
@endphp

<li class="team-activity-row @if($agent->expanded) is-expanded @endif @if($agent->isVirtual) is-virtual @endif"
    role="row"
    data-team-activity-agent="{{ $agent->id }}"
    data-team-activity-expanded="{{ $agent->expanded ? '1' : '0' }}">
    <button type="button"
            class="team-activity-row-summary @if($agent->isVirtual) team-activity-row-summary--virtual @endif"
            data-team-activity-row-toggle
            aria-expanded="{{ $agent->expanded ? 'true' : 'false' }}"
            aria-controls="{{ $historyId }}">
        <span class="team-activity-col team-activity-col--member" role="cell">
            <span class="team-activity-member">
                <x-team-activity.agent-avatar
                    :name="$agent->name"
                    :status="$agent->status->value"
                    :is-virtual="$agent->isVirtual" />
                <span class="team-activity-name" title="{{ $agent->name }}">{{ $agent->name }}</span>
            </span>
        </span>

        <span class="team-activity-col team-activity-col--status" role="cell">
            <span class="team-activity-status-stack">
                <x-team-activity.status-badge
                    :status="$agent->status->value"
                    :label="$agent->statusLabel" />
                @if(filled($agent->calendarBadge))
                    <x-team-activity.calendar-badge :label="$agent->calendarBadge" />
                @endif
                @if(! $hasPresenceMetrics && ! $agent->isVirtual && filled($agent->workingLabel))
                    <span class="team-activity-status-note" title="{{ $agent->workingLabel }}">{{ $agent->workingLabel }}</span>
                @endif
            </span>
        </span>

        <span class="team-activity-col team-activity-col--presence" role="cell">
            @if($hasPresenceMetrics)
                <span class="team-activity-presence">
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
            @else
                <span class="team-activity-presence team-activity-presence--empty" aria-hidden="true">—</span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--kpi" role="cell">
            @if($agent->isVirtual)
                <span class="team-activity-kpi team-activity-kpi--ira"
                      aria-label="{{ number_format($agent->todayCount).' '.($agent->todayCount === 1 ? 'Activity' : 'Activities') }}@if(filled($agent->supplementaryKpiCount)) +{{ number_format($agent->supplementaryKpiCount) }} {{ $agent->supplementaryKpiLabel }}@endif">
                    <span class="team-activity-kpi-primary">
                        <span class="team-activity-kpi-count">{{ number_format($agent->todayCount) }}</span>
                        <span class="team-activity-kpi-label">{{ $agent->todayCount === 1 ? 'Activity' : 'Activities' }}</span>
                    </span>
                    @if($agent->supplementaryKpiCount !== null)
                        <span class="team-activity-kpi-secondary">
                            <span class="team-activity-kpi-supplementary">+{{ number_format($agent->supplementaryKpiCount) }}</span>
                            <span class="team-activity-kpi-label">{{ $agent->supplementaryKpiLabel }}</span>
                        </span>
                    @endif
                </span>
            @else
                <span class="team-activity-kpi" aria-label="{{ number_format($agent->todayCount).' '.$activityCountLabel }}">
                    <span class="team-activity-kpi-count">{{ number_format($agent->todayCount) }}</span>
                    <span class="team-activity-kpi-label">{{ $activityCountLabel }}</span>
                </span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--latest" role="cell">
            @if($latest)
                <x-team-activity.latest-event :entry="$latest" />
            @else
                <span class="team-activity-latest-empty text-muted">—</span>
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
