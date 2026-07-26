@props([
    'panel',
])

@php
    /** @var \App\Data\TeamActivityPanel $panel */
@endphp

<div class="team-activity-panel"
     data-team-activity-panel
     data-operations-widget="team-activity"
     data-team-activity-refresh-url="{{ route('dashboard.team-activity') }}"
     data-team-activity-poll-interval-ms="{{ (int) config('dashboard-team-activity.poll_interval_ms', 30000) }}"
     data-team-activity-user-idle-ms="{{ (int) config('dashboard-team-activity.user_idle_ms', 300000) }}"
     data-team-activity-collapsed="0"
     aria-label="Team Activity">
    <div class="team-activity-panel-header">
        <h2 class="dashboard-section-title dashboard-section-title--secondary mb-0">Team Activity</h2>
        <button type="button"
                class="team-activity-panel-toggle"
                data-team-activity-panel-toggle
                aria-expanded="true"
                aria-label="Collapse Team Activity">
            <span class="team-activity-panel-chevron" aria-hidden="true"></span>
        </button>
    </div>

    <div class="team-activity-panel-body" data-team-activity-panel-body>
        @if($panel->empty || $panel->agents === [])
            <p class="team-activity-empty text-muted small mb-0">No team members to show.</p>
        @else
            <ul class="team-activity-list list-unstyled mb-0" data-team-activity-list>
                @foreach($panel->agents as $agent)
                    @include('dashboard.partials.team-activity-agent-row', ['agent' => $agent])
                @endforeach
            </ul>
        @endif
    </div>
</div>
