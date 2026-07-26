@extends('layouts.app')

@section('title', 'Platform')

@section('content')
    <x-settings-center.shell
        title="Platform"
        subtitle="Manage platform configuration, service case defaults, operational masters and administration data."
        workspace-nav="mission-control"
        workspace-active="overview"
    >
        <x-slot:sidebar>
            <x-settings-center.platform-sidebar :sections="$dashboard->sections" />
        </x-slot:sidebar>

        <x-slot:actions>
            <div class="settings-center-toolbar" data-platform-toolbar>
                <div class="settings-center-toolbar__search">
                    <x-settings-center.icon name="search" class="settings-center-icon settings-center-icon--sm settings-center-toolbar__search-icon" />
                    <input type="search"
                           class="form-control form-control-sm"
                           placeholder="Search platform…"
                           aria-label="Search platform"
                           data-platform-search
                           autocomplete="off">
                    <kbd class="settings-center-toolbar__shortcut d-none d-md-inline" aria-hidden="true">⌘K</kbd>
                </div>

                <div class="settings-center-toolbar__meta" data-platform-dashboard-generated-at>
                    Snapshot {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'H:i') }}
                </div>

                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-platform-refresh-all
                        title="Refresh all cards"
                        aria-label="Refresh all cards">
                    <x-settings-center.icon name="refresh-cw" class="settings-center-icon settings-center-icon--sm" />
                    <span class="d-none d-md-inline">Refresh</span>
                </button>
            </div>
        </x-slot:actions>

        <div id="platform-dashboard-root"
             class="settings-center-platform"
             data-platform-dashboard
             data-poll-interval-seconds="{{ $platformPollIntervalSeconds ?? 60 }}"
             data-generated-at="{{ $dashboard->generatedAt->toIso8601String() }}">
            <div class="settings-center-platform__sections" data-platform-sections>
                @forelse($dashboard->sections as $section)
                    @include('admin.platform.partials.section', ['section' => $section])
                @empty
                    <x-settings-center.empty-state
                        title="No platform cards registered"
                        description="Platform dashboard cards will appear here once providers are registered."
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
