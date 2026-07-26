@props([
    'agent',
])

@php
    /** @var \App\Data\TeamActivityAgentRow $agent */
    $latest = $agent->latest;
    $historyId = 'team-activity-history-'.$agent->id;
    $todayLabel = 'Today · '.number_format($agent->todayCount).' '.($agent->todayCount === 1 ? 'activity' : 'activities');
@endphp

<li class="team-activity-row @if($agent->expanded) is-expanded @endif @if($agent->isVirtual) is-virtual @endif"
    data-team-activity-agent="{{ $agent->id }}"
    data-team-activity-expanded="{{ $agent->expanded ? '1' : '0' }}">
    @if($agent->isVirtual)
        <div class="team-activity-row-summary team-activity-row-summary--virtual">
            <span class="team-activity-status-dot" aria-hidden="true"></span>

            <span class="team-activity-name-block">
                <span class="team-activity-name" title="{{ $agent->name }}">{{ $agent->name }}</span>
                @if(filled($agent->badge))
                    <span class="team-activity-badge">{{ $agent->badge }}</span>
                @endif
            </span>

            <span class="team-activity-status-block">
                <span class="team-activity-status-label">{{ $agent->statusLabel }}</span>
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
        </div>
    @else
    <button type="button"
            class="team-activity-row-summary"
            data-team-activity-row-toggle
            aria-expanded="{{ $agent->expanded ? 'true' : 'false' }}"
            aria-controls="{{ $historyId }}">
        <span class="team-activity-status-dot" aria-hidden="true"></span>

        <span class="team-activity-name" title="{{ $agent->name }}">{{ $agent->name }}</span>

        <span class="team-activity-status-block">
            <span class="team-activity-status-label">{{ $agent->statusLabel }}</span>
            @if(filled($agent->workingLabel))
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
            <ul class="team-activity-history-list list-unstyled mb-0">
                @foreach($agent->history as $entry)
                    <li class="team-activity-history-item"
                        @if($entry->incidentId)
                            data-dashboard-activity-entry
                            data-incident-id="{{ $entry->incidentId }}"
                        @endif>
                        <time datetime="{{ $entry->at->toIso8601String() }}">{{ $entry->time }}</time>
                        <span>{{ $entry->label }}@if(filled($entry->reference)) {{ $entry->reference }}@endif</span>
                    </li>
                @endforeach
            </ul>
        @elseif($agent->expanded)
            <p class="team-activity-history-empty text-muted small mb-0">No recent activity.</p>
        @endif
    </div>
    @endif
</li>
