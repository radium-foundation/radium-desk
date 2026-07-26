@extends('layouts.app')

@section('title', 'Platform')

@section('content')
    @php
        $executiveSection = collect($dashboard->sections)->firstWhere('key', 'executive');
        $healthSection = collect($dashboard->sections)->firstWhere('key', 'platform_health');
        $launchpadSections = collect($dashboard->sections)
            ->reject(fn (array $section): bool => in_array($section['key'] ?? '', ['executive', 'platform_health'], true))
            ->values()
            ->all();
    @endphp

    <x-settings-center.shell
        title="Platform"
        subtitle="Enterprise command center for live operations, health, and workspace navigation."
        workspace-nav="mission-control"
        workspace-active="overview"
        class="settings-center-platform-page"
    >
        <x-slot:sidebar>
            <x-settings-center.platform-sidebar :sections="$dashboard->sections" />
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
                    Last updated {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'g:i A') }}
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
                @if($executiveSection)
                    @include('admin.platform.partials.section', [
                        'section' => $executiveSection,
                        'variant' => 'executive',
                    ])
                @endif

                @if($healthSection)
                    @include('admin.platform.partials.section', [
                        'section' => $healthSection,
                        'variant' => 'health',
                    ])
                @endif

                @if($launchpadSections !== [])
                    @include('admin.platform.partials.launchpad-grid', ['sections' => $launchpadSections])
                @endif

                @if($executiveSection === null && $healthSection === null && $launchpadSections === [])
                    <x-settings-center.empty-state
                        title="No platform cards registered"
                        description="Platform dashboard cards will appear here once providers are registered."
                        icon="layout-dashboard"
                    />
                @endif
            </div>
        </div>
    </x-settings-center.shell>
@endsection

@push('vite')
    @vite('resources/js/pages/platform.js')
@endpush
