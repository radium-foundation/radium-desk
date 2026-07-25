@extends('layouts.app')

@section('title', 'Mission Control')

@section('content')
    <div
        id="platform-dashboard-root"
        data-platform-dashboard
        data-poll-interval-seconds="{{ $platformPollIntervalSeconds ?? 60 }}"
        data-generated-at="{{ $dashboard->generatedAt->toIso8601String() }}"
    >
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1">Command Center</h1>
                <p class="text-muted mb-0">Single platform workspace for health, automation, audit, finance, and system controls.</p>
            </div>
            <div class="text-muted small" data-platform-dashboard-generated-at>
                Snapshot {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'H:i') }}
            </div>
        </div>

        @include('navigation.mission-control-workspace-nav', ['active' => 'overview'])

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
