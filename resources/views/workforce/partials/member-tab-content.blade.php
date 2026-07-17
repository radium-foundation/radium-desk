@props([
    'workforce',
    'activeTab' => 'overview',
])

@php
    $overview = $workforce->overview;
    $schedule = $overview['schedule'] ?? [];
    $presence = $overview['presence'] ?? [];
    $calendar = $overview['calendar'] ?? [];
    $attendance = $overview['attendance_day'] ?? null;
    $performance = $overview['performance'] ?? [];
    $leave = $overview['leave'] ?? [];
    $weekdayLabels = config('workforce_calendar.weekday_labels', []);
@endphp

@if($activeTab === 'overview')
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Today schedule</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Work window</dt>
                        <dd class="col-7">{{ ($schedule['work_start_time'] ?? '—') . ' – ' . ($schedule['work_end_time'] ?? '—') }}</dd>
                        <dt class="col-5 text-muted">Lunch</dt>
                        <dd class="col-7">
                            @if(filled($schedule['lunch_start_time'] ?? null) && filled($schedule['lunch_end_time'] ?? null))
                                {{ $schedule['lunch_start_time'] }} – {{ $schedule['lunch_end_time'] }}
                            @else
                                —
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Break allowance</dt>
                        <dd class="col-7">{{ (int) ($schedule['short_break_count'] ?? 0) }} × {{ (int) ($schedule['short_break_minutes'] ?? 0) }} min</dd>
                        <dt class="col-5 text-muted">Weekly off</dt>
                        <dd class="col-7">
                            @php
                                $offDays = collect($schedule['weekly_off_days'] ?? [])
                                    ->map(fn ($day) => $weekdayLabels[$day] ?? $day)
                                    ->implode(', ');
                            @endphp
                            {{ $offDays !== '' ? $offDays : '—' }}
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">Presence</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Calendar</dt>
                        <dd class="col-7">{{ ($calendar['indicator'] ?? '') }} {{ $calendar['label'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Presence</dt>
                        <dd class="col-7">{{ ($presence['indicator'] ?? '') }} {{ $presence['label'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Login</dt>
                        <dd class="col-7">{{ $presence['login_at'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Active desk</dt>
                        <dd class="col-7">{{ $presence['active_duration'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Open cases</dt>
                        <dd class="col-7">{{ number_format((int) ($overview['open_work_count'] ?? 0)) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        @if(($overview['block_reason_labels'] ?? []) !== [])
            <div class="col-12">
                <div class="card border-0 shadow-sm border-warning-subtle">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0 text-warning-emphasis">Block reasons</h2>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0 small">
                            @foreach($overview['block_reason_labels'] as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Quick actions</h2>
                </div>
                <div class="card-body d-flex flex-wrap gap-2">
                    @can('create', App\Models\LeaveRequest::class)
                        <a href="{{ route('leave-requests.create') }}" class="btn btn-sm btn-outline-primary">Request leave</a>
                    @endcan
                    @if($workforce->isSelf)
                        <a href="{{ route('my-performance.index') }}" class="btn btn-sm btn-outline-secondary">My performance</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@elseif($activeTab === 'schedule')
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <dl class="row mb-0 small">
                <dt class="col-sm-3 text-muted">Configured</dt>
                <dd class="col-sm-9">{{ ($schedule['configured'] ?? false) ? 'Yes' : 'Defaults only' }}</dd>
                <dt class="col-sm-3 text-muted">Work window</dt>
                <dd class="col-sm-9">{{ ($schedule['work_start_time'] ?? '—') . ' – ' . ($schedule['work_end_time'] ?? '—') }}</dd>
                <dt class="col-sm-3 text-muted">Expected minutes</dt>
                <dd class="col-sm-9">{{ $schedule['expected_working_minutes'] ?? '—' }}</dd>
                <dt class="col-sm-3 text-muted">Lunch</dt>
                <dd class="col-sm-9">
                    @if(filled($schedule['lunch_start_time'] ?? null) && filled($schedule['lunch_end_time'] ?? null))
                        {{ $schedule['lunch_start_time'] }} – {{ $schedule['lunch_end_time'] }}
                    @else
                        —
                    @endif
                </dd>
                <dt class="col-sm-3 text-muted">Break allowance</dt>
                <dd class="col-sm-9">{{ (int) ($schedule['short_break_count'] ?? 0) }} × {{ (int) ($schedule['short_break_minutes'] ?? 0) }} min</dd>
                <dt class="col-sm-3 text-muted">Weekly off</dt>
                <dd class="col-sm-9">
                    @php
                        $offDays = collect($schedule['weekly_off_days'] ?? [])
                            ->map(fn ($day) => $weekdayLabels[$day] ?? $day)
                            ->implode(', ');
                    @endphp
                    {{ $offDays !== '' ? $offDays : '—' }}
                </dd>
            </dl>
        </div>
    </div>
@elseif($activeTab === 'attendance')
    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Today attendance</h2></div>
                <div class="card-body">
                    @if($attendance === null)
                        <p class="text-muted mb-0">No attendance register row for today yet.</p>
                    @else
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted">Status</dt>
                            <dd class="col-7">{{ $attendance['status_label'] ?? '—' }}</dd>
                            <dt class="col-5 text-muted">First login</dt>
                            <dd class="col-7">{{ $attendance['first_login_at'] ?? '—' }}</dd>
                            <dt class="col-5 text-muted">Last logout</dt>
                            <dd class="col-7">{{ $attendance['last_logout_at'] ?? '—' }}</dd>
                            <dt class="col-5 text-muted">On time</dt>
                            <dd class="col-7">
                                @if($attendance['on_time_login'] === true)
                                    Yes
                                @elseif($attendance['on_time_login'] === false)
                                    Late
                                    @if(filled($attendance['minutes_late'] ?? null))
                                        ({{ $attendance['minutes_late'] }} min)
                                    @endif
                                @else
                                    —
                                @endif
                            </dd>
                        </dl>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Today performance</h2></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Attendance</dt>
                        <dd class="col-7">{{ $performance['attendance_label'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Active desk</dt>
                        <dd class="col-7">{{ $performance['active_desk_label'] ?? '—' }}</dd>
                        <dt class="col-5 text-muted">Cases completed</dt>
                        <dd class="col-7">{{ number_format((int) ($performance['cases_completed'] ?? 0)) }}</dd>
                        <dt class="col-5 text-muted">SLA</dt>
                        <dd class="col-7">{{ $performance['sla_label'] ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@elseif($activeTab === 'leave')
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <h2 class="h6 mb-0">Leave</h2>
            @can('create', App\Models\LeaveRequest::class)
                <a href="{{ route('leave-requests.create') }}" class="btn btn-sm btn-outline-primary">Request leave</a>
            @endcan
        </div>
        <div class="card-body">
            @if(filled($leave['active'] ?? null))
                <div class="alert alert-info small">
                    On approved leave until {{ $leave['active']['end_date'] ?? '' }}.
                    @if(filled($leave['active']['reason'] ?? null))
                        Reason: {{ $leave['active']['reason'] }}
                    @endif
                </div>
            @endif

            @if(($leave['recent'] ?? []) === [])
                <p class="text-muted mb-0">No leave requests yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Dates</th>
                                <th>Status</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($leave['recent'] as $request)
                                <tr>
                                    <td>{{ $request['start_date'] }} – {{ $request['end_date'] }}</td>
                                    <td>{{ $request['status_label'] ?? $request['status'] }}</td>
                                    <td>{{ $request['reason'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@elseif($activeTab === 'workload')
    <div class="row g-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Open cases</div>
                    <div class="h3 mb-0">{{ number_format((int) ($overview['open_work_count'] ?? 0)) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Cases completed today</div>
                    <div class="h3 mb-0">{{ number_format((int) ($performance['cases_completed'] ?? 0)) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Customer replies today</div>
                    <div class="h3 mb-0">{{ number_format((int) ($performance['customer_communications'] ?? 0)) }}</div>
                </div>
            </div>
        </div>
    </div>
@elseif($activeTab === 'timeline')
    <div class="card border-0 shadow-sm">
        <div class="card-body text-muted">
            Workforce timeline will be projected through the Timeline Engine in a future sprint.
        </div>
    </div>
@else
    <div class="card border-0 shadow-sm">
        <div class="card-body text-muted">Select a tab to view workforce details.</div>
    </div>
@endif
