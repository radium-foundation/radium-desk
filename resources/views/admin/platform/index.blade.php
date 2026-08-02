@extends('layouts.app')

@section('title', 'Platform')

@section('content')
    <x-settings-center.shell
        title="Platform"
        subtitle="Enterprise command center for live operations, health, and workspace navigation."
        workspace-nav="mission-control"
        workspace-active="overview"
        class="settings-center-platform-page"
    >
        <x-slot:sidebar>
            <x-settings-center.platform-sidebar :zones="$zones" />
        </x-slot:sidebar>

        <x-slot:actions>
            <div class="settings-center-toolbar settings-center-platform-toolbar" data-platform-toolbar>
                <div class="settings-center-toolbar__search">
                    <x-settings-center.icon name="search" class="settings-center-icon settings-center-icon--sm settings-center-toolbar__search-icon" />
                    <input type="search"
                           class="form-control form-control-sm"
                           placeholder="Jump to module…"
                           aria-label="Search platform modules"
                           data-platform-search
                           autocomplete="off">
                    <kbd class="settings-center-toolbar__shortcut d-none d-md-inline" aria-hidden="true">⌘K</kbd>
                </div>

                <div class="settings-center-toolbar__meta text-muted small" data-platform-dashboard-generated-at>
                    Zone framework · snapshot first paint
                </div>

                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-platform-refresh-all
                        title="Refresh all zones"
                        aria-label="Refresh all zones">
                    <x-settings-center.icon name="refresh-cw" class="settings-center-icon settings-center-icon--sm" />
                    <span class="d-none d-md-inline">Refresh</span>
                </button>
            </div>
        </x-slot:actions>

        <div id="platform-dashboard-root"
             class="settings-center-platform"
             data-platform-dashboard
             data-platform-zones
             data-poll-interval-seconds="{{ $platformPollIntervalSeconds ?? 60 }}"
             data-zone-concurrency="3"
             data-generated-at="{{ now()->toIso8601String() }}">
            <div class="settings-center-platform__sections" data-platform-sections>
                @include('admin.platform.partials.overall-health', ['overallHealth' => $overallHealth])

                @forelse($zones as $zone)
                    @include('admin.platform.partials.zone', ['zone' => $zone])
                @empty
                    <x-settings-center.empty-state
                        title="No platform zones registered"
                        description="Platform dashboard zones will appear here once providers are registered."
                        icon="layout-dashboard"
                    />
                @endforelse
            </div>
        </div>
    </x-settings-center.shell>
@endsection

@push('vite')
    @vite('resources/js/pages/platform.js')
@endpush
