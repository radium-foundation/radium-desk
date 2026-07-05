@props([
    'members' => [],
])

<section class="mb-4" aria-labelledby="team-availability-heading">
    <h2 id="team-availability-heading" class="h5 mb-3">Today's Team</h2>

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
                    @endphp
                    <div class="list-group-item px-3 py-3">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-2">
                            <div>
                                <div class="fw-semibold">{{ $member['name'] }}</div>
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
                            @if(filled($workCalendar['work_hours'] ?? null))
                                <span>{{ $workCalendar['work_hours'] }}</span>
                            @endif

                            @if(filled($workCalendar['lunch_time'] ?? null))
                                <span>Lunch {{ $workCalendar['lunch_time'] }}</span>
                            @endif

                            @if(($member['open_work_count'] ?? 0) > 0)
                                <span>Open work: {{ number_format($member['open_work_count']) }}</span>
                            @endif

                            @if(filled($member['last_active_relative'] ?? null))
                                <span>Last active: {{ $member['last_active_relative'] }}</span>
                            @endif

                            @if(filled($member['work_activity_relative'] ?? null) && filled($member['work_activity_label'] ?? null))
                                <span>{{ $member['work_activity_label'] }}: {{ $member['work_activity_relative'] }}</span>
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
