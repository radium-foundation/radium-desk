@props([
    'agent',
    'ivrCallsTotalToday' => 0,
])

@php
    /** @var \App\Data\TeamActivityAgentRow $agent */
    use App\Support\Dashboard\TeamActivityMemberStatusPresenter;

    $latest = $agent->latest;
    $memberStatusPresenter = app(TeamActivityMemberStatusPresenter::class);
    $historyId = 'team-activity-history-'.$agent->id;
    $outcomeLabel = $agent->outcomeLabel ?? 'Cases Worked';
    $effortLabel = $agent->effortLabel ?? 'Customer Touches';
    $outcomeCount = $agent->outcomeCount ?? $agent->todayCount;
    $effortCount = $agent->effortCount ?? 0;
    $isActivationProfile = $agent->kpiProfile?->value === 'activation';
    $displayName = \Illuminate\Support\Str::before(trim($agent->name), ' ') ?: $agent->name;
    $latestElapsed = $agent->latestActivityAt
        ? display_team_activity_elapsed($agent->latestActivityAt)
        : null;
    $previousElapsed = $agent->previousActivityAt
        ? display_team_activity_elapsed($agent->previousActivityAt)
        : null;

    if ($isActivationProfile) {
        $primaryCount = $effortCount;
        $superscriptCount = $outcomeCount;
        $primaryLabel = $effortLabel;
        $superscriptLabel = $outcomeLabel;
        $kpiTitle = number_format($primaryCount).' '.$primaryLabel."\n".number_format($superscriptCount).' '.$superscriptLabel;
        $kpiAriaLabel = ($effortCount === 1 ? "1 {$effortLabel}" : number_format($effortCount).' '.$effortLabel)
            .'; '
            .($outcomeCount === 1 ? "1 {$outcomeLabel}" : number_format($outcomeCount).' '.$outcomeLabel);
    } else {
        $primaryCount = $outcomeCount;
        $superscriptCount = $effortCount;
        $primaryLabel = $outcomeLabel;
        $superscriptLabel = $effortLabel;
        $kpiTitle = number_format($primaryCount).' '.$primaryLabel."\n".number_format($superscriptCount).' '.$superscriptLabel;
        $kpiAriaLabel = ($outcomeCount === 1 ? "1 {$outcomeLabel}" : number_format($outcomeCount).' '.$outcomeLabel)
            .'; '
            .($effortCount === 1 ? "1 {$effortLabel}" : number_format($effortCount).' '.$effortLabel);
    }

    $hasPresenceMetrics = ! $agent->isVirtual && (
        filled($agent->todayDurationLabel)
        || filled($agent->currentDurationLabel)
        || filled($agent->sessionsToday)
        || filled($latestElapsed)
        || filled($previousElapsed)
    );

    $hasCallMetrics = ! $agent->isVirtual
        && $agent->callsAnsweredToday !== null
        && filled($agent->callsTalkDurationLabel)
        && (
            $agent->callsAnsweredToday > 0
            || $ivrCallsTotalToday > 0
            || $agent->callsTalkDurationLabel !== '0m'
        );

    if ($hasCallMetrics) {
        $callsTitle = number_format($agent->callsAnsweredToday).' calls answered'
            ."\n".number_format($ivrCallsTotalToday).' total IVR calls received today (team-wide)'
            ."\n".$agent->callsTalkDurationLabel.' talk time today';
        $callsAriaLabel = ($agent->callsAnsweredToday === 1 ? '1 call answered' : number_format($agent->callsAnsweredToday).' calls answered')
            .'; '
            .($ivrCallsTotalToday === 1 ? '1 total IVR call received today team-wide' : number_format($ivrCallsTotalToday).' total IVR calls received today team-wide')
            .'; '
            .$agent->callsTalkDurationLabel.' talk time today';
    }

    $pendingCount = $agent->pendingCasesCount ?? 0;
    $overdueCount = $agent->overdueCasesCount ?? 0;
    $hasPendingMetrics = ! $agent->isVirtual && $agent->pendingCasesCount !== null;

    if ($hasPendingMetrics) {
        $pendingTitle = 'Pending Cases'."\n".'Overdue: '.number_format($overdueCount);
        $pendingAriaLabel = ($pendingCount === 1 ? '1 pending case' : number_format($pendingCount).' pending cases')
            .'; '
            .($overdueCount === 1 ? '1 overdue case' : number_format($overdueCount).' overdue cases');
    }

    $statusContext = $memberStatusPresenter->contextLabel($agent, $latestElapsed);
    $statusAriaLabel = $memberStatusPresenter->ariaLabel($agent, $latestElapsed);
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
                <span class="team-activity-member-text"
                      aria-label="{{ $displayName }}, {{ $statusAriaLabel }}">
                    <span class="team-activity-name" title="{{ $agent->name }}">{{ $displayName }}</span>
                    <x-team-activity.member-status
                        :status="$agent->status->value"
                        :label="$agent->statusLabel"
                        :context="$statusContext"
                        aria-hidden="true" />
                    @if(filled($agent->calendarBadge))
                        <x-team-activity.calendar-badge
                            class="team-activity-member-calendar"
                            :label="$agent->calendarBadge" />
                    @endif
                </span>
            </span>
        </span>

        <span class="team-activity-col team-activity-col--presence" role="cell">
            @if($hasPresenceMetrics)
                <span class="team-activity-presence" aria-label="Today {{ $agent->todayDurationLabel ?: '—' }}, Current {{ $agent->currentDurationLabel ?: '—' }}, Sessions {{ filled($agent->sessionsToday) ? number_format($agent->sessionsToday) : '—' }}, Latest {{ $latestElapsed ?: '—' }}, Previous {{ $previousElapsed ?: '—' }}">
                    <span class="team-activity-metric-value"><x-team-activity.duration :value="$agent->todayDurationLabel ?: '—'" /></span>
                    <span class="team-activity-metric-value"><x-team-activity.duration :value="$agent->currentDurationLabel ?: '—'" /></span>
                    <span class="team-activity-metric-value">{{ filled($agent->sessionsToday) ? number_format($agent->sessionsToday) : '—' }}</span>
                    <span class="team-activity-metric-value"><x-team-activity.duration :value="$latestElapsed ?: '—'" /></span>
                    <span class="team-activity-metric-value"><x-team-activity.duration :value="$previousElapsed ?: '—'" /></span>
                </span>
            @else
                <span class="team-activity-presence team-activity-presence--empty" aria-hidden="true">—</span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--latest" role="cell">
            @if($latest)
                <x-team-activity.latest-event :entry="$latest" />
            @else
                <span class="team-activity-latest-empty text-muted">—</span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--calls" role="cell">
            @if($hasCallMetrics)
                <span class="team-activity-calls team-activity-calls-compact"
                      title="{{ $callsTitle }}"
                      aria-label="{{ $callsAriaLabel }}">
                    <span class="team-activity-calls-compact__figure">
                        <span class="team-activity-calls-compact__count">{{ number_format($agent->callsAnsweredToday) }}</span>
                        <sup class="team-activity-calls-compact__sup"
                              title="Total IVR calls received today (team-wide)">{{ number_format($ivrCallsTotalToday) }}</sup>
                    </span>
                    <span class="team-activity-calls-compact__separator" aria-hidden="true">·</span>
                    <span class="team-activity-calls-compact__duration"><x-team-activity.duration :value="$agent->callsTalkDurationLabel" /></span>
                    <span class="visually-hidden">Calls answered, total IVR calls, talk duration</span>
                </span>
            @else
                <span class="team-activity-calls team-activity-calls--empty" aria-hidden="true">—</span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--pending" role="cell">
            @if($hasPendingMetrics)
                <span class="team-activity-calls team-activity-calls-compact team-activity-pending-compact"
                      title="{{ $pendingTitle }}"
                      aria-label="{{ $pendingAriaLabel }}">
                    <span class="team-activity-calls-compact__figure">
                        <span class="team-activity-calls-compact__count">{{ number_format($pendingCount) }}</span>
                        @if($overdueCount > 0)
                            <sup class="team-activity-calls-compact__sup">{{ number_format($overdueCount) }}</sup>
                        @endif
                    </span>
                    <span class="visually-hidden">Pending cases, overdue cases</span>
                </span>
            @else
                <span class="team-activity-calls team-activity-calls--empty" aria-hidden="true">—</span>
            @endif
        </span>

        <span class="team-activity-col team-activity-col--kpi" role="cell">
            @if($agent->isVirtual)
                @php
                    $iraTitle = 'Unique customer cases (Reference Nos.) you worked on today. Multiple actions on the same case count once.';
                    $iraAria = $agent->todayCount === 1
                        ? '1 Cases Worked'
                        : number_format($agent->todayCount).' Cases Worked';
                    if ($agent->supplementaryKpiCount !== null) {
                        $iraAria .= ' +'.number_format($agent->supplementaryKpiCount).' '.($agent->supplementaryKpiLabel ?: 'automation');
                    }
                @endphp
                <span class="team-activity-kpi team-activity-kpi--ira team-activity-today-activity team-activity-kpi-compact"
                      title="{{ $iraTitle }}"
                      aria-label="{{ $iraAria }}">
                    <span class="team-activity-kpi-compact__figure">
                        <span class="team-activity-kpi-count">{{ number_format($agent->todayCount) }}</span>
                        @if($agent->supplementaryKpiCount !== null)
                            <sup class="team-activity-kpi-compact__sup team-activity-kpi-supplementary">{{ number_format($agent->supplementaryKpiCount) }}</sup>
                        @endif
                    </span>
                    <span class="visually-hidden team-activity-kpi-label">Cases Worked</span>
                </span>
            @else
                <span class="team-activity-kpi team-activity-kpi--dual team-activity-today-activity team-activity-kpi-compact"
                      title="{{ $kpiTitle }}"
                      aria-label="{{ $kpiAriaLabel }}">
                    <span class="team-activity-kpi-compact__figure">
                        <span class="team-activity-kpi-count">{{ number_format($primaryCount) }}</span>
                        <sup class="team-activity-kpi-compact__sup">{{ number_format($superscriptCount) }}</sup>
                    </span>
                    <span class="visually-hidden team-activity-kpi-label">{{ $primaryLabel }}</span>
                    <span class="visually-hidden team-activity-kpi-label">{{ $superscriptLabel }}</span>
                </span>
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
            <p class="team-activity-history-empty text-muted small mb-0">No cases worked today.</p>
        @endif
    </div>
</li>
