@extends('layouts.app')

@section('title', 'Team Performance')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Team Performance</h1>
        <p class="text-muted mb-0">Operational intelligence for workforce attendance, presence, and customer work.</p>
    </div>

    @include('partials.performance-period-filter', [
        'action' => route('admin.workforce.performance.index'),
        'period' => $period,
        'customStart' => $customStart,
        'customEnd' => $customEnd,
    ])

    @include('partials.ira-performance-insights', ['insights' => $insights])

    @if($teamMetrics === [])
        <div class="card border-0 shadow-sm">
            <div class="card-body text-muted">No team members found.</div>
        </div>
    @else
        <div class="row g-4">
            @foreach($teamMetrics as $metrics)
                <div class="col-lg-6">
                    @include('partials.team-member-performance-card', ['metrics' => $metrics])
                </div>
            @endforeach
        </div>
    @endif
@endsection
