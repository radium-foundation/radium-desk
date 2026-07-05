@props([
    'metrics',
    'showName' => true,
])

@php
    $attendance = $metrics->attendance;
    $login = $metrics->login;
    $presence = $metrics->presence;
    $customerWork = $metrics->customerWork;
    $quality = $metrics->quality;
@endphp

<div class="card border-0 shadow-sm h-100">
    <div class="card-body">
        @if($showName)
            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h6 mb-1">{{ $metrics->name }}</h2>
                    @if(filled($metrics->roleLabel))
                        <div class="text-muted small">{{ $metrics->roleLabel }}</div>
                    @endif
                </div>
            </div>
        @endif

        <div class="row g-3 small">
            <div class="col-md-6">
                <div class="fw-semibold mb-1">Attendance</div>
                <div>{{ $attendance['attendance_label'] ?? '—' }}</div>
                @if(($attendance['leave_days'] ?? 0) > 0)
                    <div class="text-muted">Leave: {{ $attendance['leave_days'] }} day{{ $attendance['leave_days'] === 1 ? '' : 's' }}</div>
                @endif
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">On-time</div>
                <div>{{ $login['on_time_label'] ?? '—' }}</div>
                @if(($login['late_days'] ?? 0) > 0)
                    <div class="text-muted">Late days: {{ $login['late_days'] }}</div>
                @endif
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">Active Desk</div>
                <div>{{ $presence['active_desk_average_label'] ?? '—' }} avg</div>
                <div class="text-muted">Total: {{ $presence['active_desk_label'] ?? '—' }}</div>
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">Cases</div>
                <div>{{ number_format($customerWork['cases_handled'] ?? 0) }} handled</div>
                <div class="text-muted">{{ number_format($customerWork['cases_completed'] ?? 0) }} completed</div>
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">Avg Resolution</div>
                <div>{{ $customerWork['average_resolution_label'] ?? '—' }}</div>
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">SLA</div>
                <div>{{ $quality['sla_label'] ?? '—' }}</div>
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">Customer replies</div>
                <div>{{ number_format($customerWork['customer_communications'] ?? 0) }}</div>
            </div>

            <div class="col-md-6">
                <div class="fw-semibold mb-1">Overtime</div>
                <div>{{ $presence['overtime_label'] ?? '—' }}</div>
            </div>
        </div>
    </div>
</div>
