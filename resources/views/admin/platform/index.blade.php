@extends('layouts.app')

@section('title', 'Command Center')

@section('content')
    <div
        id="platform-dashboard-root"
        data-platform-dashboard
    >
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1">Command Center</h1>
                <p class="text-muted mb-0">Platform, business, customers, team, money, and automation at a glance.</p>
            </div>
            <div class="text-muted small" data-platform-dashboard-generated-at>
                Snapshot {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'H:i') }}
            </div>
        </div>

        @forelse($dashboard->sections as $section)
            @include('admin.platform.partials.section', ['section' => $section])
        @empty
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <p class="text-muted mb-0">No platform cards are registered yet.</p>
                </div>
            </div>
        @endforelse
    </div>
@endsection
