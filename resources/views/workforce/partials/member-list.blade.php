@props([
    'members' => [],
])

<section aria-label="Team members">
    @if($members === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">No attendance-tracked team members are scheduled right now.</div>
        </div>
    @else
        <div class="card border-0 shadow-sm operations-team-card">
            <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between">
                <h2 class="h6 mb-0">Team members</h2>
                <span class="text-muted small">{{ count($members) }} visible</span>
            </div>
            <div class="operations-team-header d-none d-md-grid">
                <span>Name</span>
                <span>Status</span>
                <span>Working time</span>
                <span>Workload</span>
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
                        $workloadTone = $openWork >= 6 ? 'danger' : ($openWork >= 3 ? 'warning' : 'healthy');
                        $availabilityLabel = $availability['label'] ?? 'Offline';
                        $availabilityClass = $availability['badge_class'] ?? 'secondary';
                        $statusTone = match ($availabilityClass) {
                            'success' => 'healthy',
                            'warning' => 'warning',
                            'danger' => 'danger',
                            default => filled($member['unavailability_label'] ?? null) ? 'warning' : 'info',
                        };
                        $workingTime = $workCalendar['work_hours']
                            ?? (($presence['active_duration'] ?? '0m') !== '0m' ? $presence['active_duration'] : null);
                        $lastActivity = $presence['last_work_activity_label'] ?? $member['work_activity_label'] ?? null;
                        $lastActivityAt = $presence['last_work_activity_at'] ?? $member['work_activity_relative'] ?? null;
                    @endphp
                    <div class="list-group-item px-3 py-3 operations-team-row">
                        <div class="operations-team-grid">
                            <div class="operations-team-cell operations-team-cell--name">
                                @if(filled($member['profile_url'] ?? null))
                                    <a href="{{ $member['profile_url'] }}" class="fw-semibold text-decoration-none">{{ $member['name'] }}</a>
                                @else
                                    <div class="fw-semibold">{{ $member['name'] }}</div>
                                @endif
                                @if(filled($member['role_label'] ?? null))
                                    <div class="text-muted small d-md-none">{{ $member['role_label'] }}</div>
                                @endif
                                @if(filled($member['unavailability_label'] ?? null))
                                    <div class="text-warning-emphasis small">{{ $member['unavailability_label'] }}</div>
                                @endif
                            </div>

                            <div class="operations-team-cell" data-label="Status">
                                <span @class(['operations-team-status-pill', 'operations-team-status-pill--' . $statusTone])>
                                    <span class="operations-team-status-dot" aria-hidden="true"></span>
                                    <span>{{ $availabilityLabel }}</span>
                                </span>
                            </div>

                            <div class="operations-team-cell" data-label="Working time">
                                <span class="small">{{ $workingTime ?? '—' }}</span>
                            </div>

                            <div class="operations-team-cell" data-label="Workload">
                                <div class="operations-team-workload">
                                    <span @class(['operations-team-workload-value', 'text-danger' => $workloadTone === 'danger', 'text-warning' => $workloadTone === 'warning'])>{{ number_format($openWork) }}</span>
                                </div>
                            </div>

                            <div class="operations-team-cell" data-label="Last activity">
                                @if(filled($lastActivity))
                                    <div class="small">{{ $lastActivity }}</div>
                                    @if(filled($lastActivityAt))
                                        <div class="text-muted small">{{ $lastActivityAt }}</div>
                                    @endif
                                @else
                                    <span class="text-muted small">No recent activity</span>
                                @endif
                            </div>

                            <div class="operations-team-cell operations-team-cell--actions">
                                @if(filled($member['profile_url'] ?? null))
                                    <a href="{{ $member['profile_url'] }}" class="btn btn-sm btn-outline-secondary">Open</a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
