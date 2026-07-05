@props([
    'members' => [],
])

<section class="mb-4" aria-labelledby="team-presence-heading">
    <h2 id="team-presence-heading" class="h5 mb-3">Team Presence</h2>

    @if($members === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted small mb-0">No active team members found.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm">
            <div class="list-group list-group-flush">
                @foreach($members as $member)
                    @php
                        $workCalendar = $member['work_calendar'] ?? [];
                        $availability = $member['availability'] ?? [];
                        $presence = $member['presence'] ?? [];
                    @endphp
                    <div class="list-group-item px-3 py-3">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2">
                            <div>
                                <div class="fw-semibold">
                                    {{ $member['name'] }}
                                    @if(filled($presence['indicator'] ?? null))
                                        <span class="ms-1">{{ $presence['indicator'] }} {{ $presence['label'] ?? '' }}</span>
                                        @if(($presence['status'] ?? '') === 'idle' && ($presence['inactivity_minutes'] ?? 0) > 0)
                                            <span class="text-muted fw-normal small">{{ $presence['inactivity_minutes'] }}m</span>
                                        @endif
                                    @endif
                                </div>
                                @if(filled($member['role_label'] ?? null))
                                    <div class="text-muted small">{{ $member['role_label'] }}</div>
                                @endif
                            </div>
                            <div class="d-flex flex-wrap gap-2 align-self-md-start">
                                @if(filled($workCalendar['indicator'] ?? null))
                                    <span class="badge bg-{{ $workCalendar['badge_class'] ?? 'secondary' }}">
                                        {{ $workCalendar['indicator'] }} {{ $workCalendar['label'] ?? 'Unknown' }}
                                    </span>
                                @endif
                                <span class="badge bg-{{ $availability['badge_class'] ?? 'secondary' }}">
                                    {{ $availability['label'] ?? 'Offline' }}
                                </span>
                            </div>
                        </div>

                        <div class="text-muted small mt-2 d-flex flex-column gap-1">
                            @if(filled($presence['login_at'] ?? null))
                                <span>Login: {{ $presence['login_at'] }}</span>
                            @endif

                            @if(filled($presence['active_duration'] ?? null) && ($presence['active_duration'] ?? '0m') !== '0m')
                                <span>Active: {{ $presence['active_duration'] }}</span>
                            @endif

                            @if(filled($presence['idle_duration'] ?? null) && ($presence['idle_duration'] ?? '0m') !== '0m')
                                <span>Idle: {{ $presence['idle_duration'] }}</span>
                            @endif

                            @if(($presence['cases_handled_count'] ?? 0) > 0)
                                <span>Cases: {{ number_format($presence['cases_handled_count']) }}</span>
                            @endif

                            @if(filled($presence['last_work_activity_label'] ?? null))
                                <span>
                                    Last action:
                                    {{ $presence['last_work_activity_label'] }}
                                    @if(filled($presence['last_work_activity_at'] ?? null))
                                        {{ $presence['last_work_activity_at'] }}
                                    @endif
                                </span>
                            @endif

                            @if(filled($workCalendar['work_hours'] ?? null))
                                <span>{{ $workCalendar['work_hours'] }}</span>
                            @endif

                            @if(filled($workCalendar['lunch_time'] ?? null))
                                <span>Lunch {{ $workCalendar['lunch_time'] }}</span>
                            @endif

                            @if(($member['open_work_count'] ?? 0) > 0)
                                <span>Open work: {{ number_format($member['open_work_count']) }}</span>
                            @endif

                            @if(($availability['on_leave'] ?? false) && filled($availability['leave_start_date'] ?? null))
                                <span>
                                    Manual leave:
                                    {{ display_app_date(\Illuminate\Support\Carbon::parse($availability['leave_start_date'])) }}
                                    @if(filled($availability['leave_end_date'] ?? null))
                                        – {{ display_app_date(\Illuminate\Support\Carbon::parse($availability['leave_end_date'])) }}
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
