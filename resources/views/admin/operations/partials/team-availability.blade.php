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
        <div class="card border-0 shadow-sm operations-team-card">
            <div class="operations-team-header d-none d-md-grid">
                <span>Name</span>
                <span>Status</span>
                <span>Working time</span>
                <span>Workload</span>
                <span>Last activity</span>
                <span class="visually-hidden">Details</span>
            </div>

            <div class="list-group list-group-flush">
                @foreach($members as $member)
                    @php
                        $workCalendar = $member['work_calendar'] ?? [];
                        $availability = $member['availability'] ?? [];
                        $presence = $member['presence'] ?? [];
                        $collapseId = 'team-member-details-'.$member['id'];
                        $workingTime = $workCalendar['work_hours']
                            ?? (($presence['active_duration'] ?? '0m') !== '0m' ? $presence['active_duration'] : null);
                        $lastActivity = $presence['last_work_activity_label'] ?? $member['work_activity_label'] ?? null;
                        $lastActivityAt = $presence['last_work_activity_at'] ?? $member['work_activity_relative'] ?? null;
                    @endphp
                    <div class="list-group-item px-3 py-3 operations-team-row">
                        <div class="operations-team-grid">
                            <div class="operations-team-cell operations-team-cell--name">
                                <div class="fw-semibold">{{ $member['name'] }}</div>
                                @if(filled($member['role_label'] ?? null))
                                    <div class="text-muted small d-md-none">{{ $member['role_label'] }}</div>
                                @endif
                            </div>

                            <div class="operations-team-cell" data-label="Status">
                                <div class="d-flex flex-wrap gap-1">
                                    @if(filled($workCalendar['indicator'] ?? null))
                                        <span class="badge bg-{{ $workCalendar['badge_class'] ?? 'secondary' }}">
                                            {{ $workCalendar['indicator'] }} {{ $workCalendar['label'] ?? 'Unknown' }}
                                        </span>
                                    @endif
                                    <span class="badge bg-{{ $availability['badge_class'] ?? 'secondary' }}">
                                        {{ $availability['label'] ?? 'Offline' }}
                                    </span>
                                    @if(filled($presence['indicator'] ?? null))
                                        <span class="badge text-bg-light border text-muted">
                                            {{ $presence['indicator'] }} {{ $presence['label'] ?? '' }}
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="operations-team-cell" data-label="Working time">
                                <span class="small">{{ $workingTime ?? '—' }}</span>
                            </div>

                            <div class="operations-team-cell" data-label="Workload">
                                <span class="fw-semibold">{{ number_format($member['open_work_count'] ?? 0) }}</span>
                                <span class="text-muted small">open</span>
                            </div>

                            <div class="operations-team-cell" data-label="Last activity">
                                @if(filled($lastActivity))
                                    <div class="small">{{ $lastActivity }}</div>
                                    @if(filled($lastActivityAt))
                                        <div class="text-muted small">{{ $lastActivityAt }}</div>
                                    @endif
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </div>

                            <div class="operations-team-cell operations-team-cell--actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary"
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
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
