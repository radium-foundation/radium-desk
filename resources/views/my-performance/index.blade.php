@extends('layouts.app')

@section('title', 'Your Performance')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Your {{ $metrics->range->label() }}</h1>
        <p class="text-muted mb-0">Your operational stats for the selected period.</p>
    </div>

    @include('partials.performance-period-filter', [
        'action' => route('my-performance.index'),
        'period' => $period,
        'customStart' => $customStart,
        'customEnd' => $customEnd,
    ])

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Active</div>
                    <div class="h4 mb-0">{{ $metrics->presence['active_desk_label'] ?? '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Completed</div>
                    <div class="h4 mb-0">{{ number_format($metrics->customerWork['cases_completed'] ?? 0) }} cases</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Customer replies</div>
                    <div class="h4 mb-0">{{ number_format($metrics->customerWork['customer_communications'] ?? 0) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">SLA</div>
                    <div class="h4 mb-0">{{ $metrics->quality['sla_label'] ?? '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Overtime</div>
                    <div class="h4 mb-0">{{ $metrics->presence['overtime_label'] ?? '—' }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Attendance</div>
                    <div class="h4 mb-0">{{ $metrics->attendance['attendance_label'] ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    @include('partials.team-member-performance-card', [
        'metrics' => $metrics,
        'showName' => false,
    ])
@endsection
