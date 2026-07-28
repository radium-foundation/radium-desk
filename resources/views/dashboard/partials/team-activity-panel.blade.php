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
            <div class="team-activity-grid" role="table" aria-label="Team activity roster">
                <div class="team-activity-grid-header" role="row">
                    <span class="team-activity-grid-header__cell team-activity-col--member" role="columnheader">Team Member</span>
                    <span class="team-activity-grid-header__cell team-activity-col--presence team-activity-grid-header__cell--presence" role="columnheader">
                        <span class="team-activity-grid-header__title">Presence</span>
                        <span class="team-activity-grid-header__sub team-activity-presence-head" aria-hidden="true">
                            <span>Today</span>
                            <span>Current</span>
                            <span>Sessions</span>
                            <span>Latest</span>
                            <span>Previous</span>
                        </span>
                        <span class="visually-hidden">Today, Current, Sessions, Latest, Previous</span>
                    </span>
                    <span class="team-activity-grid-header__cell team-activity-col--latest" role="columnheader">Latest Event</span>
                    <span class="team-activity-grid-header__cell team-activity-col--calls" role="columnheader">Calls</span>
                    <span class="team-activity-grid-header__cell team-activity-col--kpi"
                          role="columnheader"
                          title="Support: Cases Worked with Customer Touches as superscript. Activation: Activation Sessions with Orders Activated as superscript.">
                        <span class="team-activity-grid-header__title">Today's Activity</span>
                        <span class="team-activity-grid-header__sub">Outcome</span>
                        <span class="visually-hidden">Outcome · Effort</span>
                    </span>
                    <span class="team-activity-grid-header__cell team-activity-col--chevron" aria-hidden="true"></span>
                </div>

                <ul class="team-activity-list list-unstyled mb-0" data-team-activity-list role="rowgroup">
                    @foreach($panel->agents as $agent)
                        @include('dashboard.partials.team-activity-agent-row', ['agent' => $agent])
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>
