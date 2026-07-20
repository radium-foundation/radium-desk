@props([
    'members' => [],
])

<section aria-label="Team members">
    @if($members === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">No attendance-tracked team members are scheduled right now.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm operations-team-card workforce360-team-card">
            <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0">Team members</h2>
                <span class="text-muted small">{{ count($members) }} visible</span>
            </div>
            <div class="workforce360-team-table-scroll">
            <div class="workforce360-team-header d-none d-lg-grid">
                <span>Name</span>
                <span>Status</span>
                <span>Shift</span>
                <span>Active Today</span>
                <span>Workload</span>
                <span>Current Case</span>
                <span>Last activity</span>
                <span class="visually-hidden">Open</span>
            </div>
            <div class="list-group list-group-flush">
                @foreach($members as $member)
                    @php
                        $availability = $member['availability'] ?? [];
                        $presence = $member['presence'] ?? [];
                        $workCalendar = $member['work_calendar'] ?? [];
                        $openWork = (int) ($member['open_work_count'] ?? 0);
                        $availabilityLabel = $availability['label'] ?? 'Offline';
                        $availabilityClass = $availability['badge_class'] ?? 'secondary';
                        $statusTone = match ($availabilityClass) {
                            'success' => 'healthy',
                            'warning' => 'warning',
                            'danger' => 'danger',
                            default => filled($member['status_reason'] ?? null) ? 'warning' : 'info',
                        };
                        $statusReason = $member['status_reason'] ?? null;
                        $shift = $workCalendar['work_hours'] ?? null;
                        $activeTime = filled($presence['login_at'] ?? null)
                            ? ($presence['active_duration'] ?? '0m')
                            : null;
                        $lastActivity = $presence['last_work_activity_label'] ?? $member['work_activity_label'] ?? null;
                        $lastActivityAt = $presence['last_work_activity_at'] ?? $member['work_activity_relative'] ?? null;
                        $currentCase = $member['current_case'] ?? null;
                        $caseMeta = collect([
                            $currentCase['category'] ?? null,
                            $currentCase['status_label'] ?? null,
                        ])->filter()->implode(' • ');
                    @endphp
                    <div class="list-group-item px-3 py-2 workforce360-team-row">
                        <div class="workforce360-team-grid">
                            <div class="workforce360-team-cell workforce360-team-cell--name">
                                @if(filled($member['profile_url'] ?? null))
                                    <a href="{{ $member['profile_url'] }}" class="fw-semibold text-decoration-none workforce360-truncate" title="{{ $member['name'] }}">{{ $member['name'] }}</a>
                                @else
                                    <div class="fw-semibold workforce360-truncate" title="{{ $member['name'] }}">{{ $member['name'] }}</div>
                                @endif
                                @if(filled($member['role_label'] ?? null))
                                    <div class="text-muted small d-lg-none">{{ $member['role_label'] }}</div>
                                @endif
                            </div>

                            <div class="workforce360-team-cell" data-label="Status">
                                <span @class(['operations-team-status-pill', 'operations-team-status-pill--' . $statusTone])>
                                    <span class="operations-team-status-dot" aria-hidden="true"></span>
                                    <span>{{ $availabilityLabel }}</span>
                                </span>
                                @if(filled($statusReason))
                                    <div class="workforce360-status-reason text-muted small mt-1" title="{{ $statusReason }}">{{ $statusReason }}</div>
                                @endif
                            </div>

                            <div class="workforce360-team-cell" data-label="Shift">
                                <span class="small">{{ $shift ?? '—' }}</span>
                            </div>

                            <div class="workforce360-team-cell" data-label="Active Today">
                                <span class="small">{{ $activeTime ?? '—' }}</span>
                            </div>

                            <div class="workforce360-team-cell" data-label="Workload">
                                @include('workforce.partials.workload-cell', ['openWork' => $openWork])
                            </div>

                            <div class="workforce360-team-cell" data-label="Current Case">
                                @if(is_array($currentCase) && filled($currentCase['reference'] ?? null))
                                    <div class="workforce360-current-case">
                                        <div class="workforce360-current-case__reference small fw-semibold workforce360-truncate" title="{{ $currentCase['reference'] }}">{{ $currentCase['reference'] }}</div>
                                        @if(filled($caseMeta))
                                            <div class="workforce360-current-case__meta text-muted small workforce360-truncate" title="{{ $caseMeta }}">{{ $caseMeta }}</div>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </div>

                            <div class="workforce360-team-cell" data-label="Last activity">
                                @if(filled($lastActivity))
                                    <div class="small workforce360-truncate" title="{{ $lastActivity }}">{{ $lastActivity }}</div>
                                    @if(filled($lastActivityAt))
                                        <div class="text-muted small">{{ $lastActivityAt }}</div>
                                    @endif
                                @else
                                    <span class="text-muted small">No recent activity</span>
                                @endif
                            </div>

                            <div class="workforce360-team-cell workforce360-team-cell--actions">
                                @if(filled($member['profile_url'] ?? null))
                                    <a href="{{ $member['profile_url'] }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
            </div>
        </div>
    @endif
</section>
