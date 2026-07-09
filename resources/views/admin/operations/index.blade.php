@extends('layouts.app')

@section('title', 'Operations Control Center')

@section('content')
    <div
        id="operations-dashboard-root"
        data-live-url="{{ route('admin.operations.live') }}"
        data-live-interval="30000"
        data-live-full-interval="120000"
    >
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
            <div>
                <h1 class="h3 mb-1">Operations Control Center</h1>
                <p class="text-muted mb-0">Command center for live operational health, support load, and team status.</p>
            </div>
            <div class="operations-dashboard-meta text-muted small" id="operations-dashboard-generated-at">
                <span class="operations-live-indicator" aria-hidden="true">● Live</span>
                Updated {{ \App\Support\AppDateFormatter::format($dashboard->generatedAt, 'H:i') }}
            </div>
        </div>

        <section class="operations-command-center" aria-label="Operations command center">
            <div id="operations-critical-alerts" class="operations-bento-row operations-bento-row--alerts">
                @include('admin.operations.partials.critical-alerts', [
                    'dashboard' => $dashboard,
                    'briefing' => null,
                ])
            </div>

            <div class="operations-bento-grid mb-3">
                <div id="operations-overview-cards" class="operations-bento-cell operations-bento-cell--overview">
                    @include('admin.operations.partials.overview-cards', [
                        'dashboard' => $dashboard,
                        'members' => $dashboard->teamAvailability['on_duty'] ?? [],
                        'intelligence' => $dashboard->supportIntelligence,
                    ])
                </div>

                <div id="operations-ira-briefing-compact" class="operations-bento-cell operations-bento-cell--ira">
                    @include('admin.operations.partials.ira-briefing-compact')
                </div>
            </div>

            <div id="operations-health-status" class="operations-bento-row operations-bento-row--health">
                @include('admin.operations.partials.health-status-compact', [
                    'cashfreeHealth' => $dashboard->cashfreeHealth,
                    'radiumBoxHealth' => $dashboard->radiumBoxHealth,
                    'teamTelegramStatus' => $dashboard->teamTelegramStatus,
                    'integrationHealth' => $dashboard->integrationHealth,
                ])
            </div>
        </section>

        <div id="operations-tabs-sentinel" class="operations-tabs-sentinel" aria-hidden="true"></div>

        <div class="operations-dashboard-tabs card border-0 shadow-sm operations-card-hover">
            <div class="card-header bg-white border-bottom-0 pb-0 operations-dashboard-tabs-header">
                <ul class="nav nav-tabs card-header-tabs operations-dashboard-tablist flex-nowrap overflow-auto" id="operations-dashboard-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link active"
                            id="operations-tab-today"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-today"
                            data-operations-live-group="today"
                            type="button"
                            role="tab"
                            aria-controls="operations-pane-today"
                            aria-selected="true"
                        >
                            Today
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="operations-tab-team"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-team"
                            data-operations-live-group="team"
                            type="button"
                            role="tab"
                            aria-controls="operations-pane-team"
                            aria-selected="false"
                        >
                            Team
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="operations-tab-performance"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-performance"
                            data-operations-live-group="performance"
                            type="button"
                            role="tab"
                            aria-controls="operations-pane-performance"
                            aria-selected="false"
                        >
                            Performance
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link"
                            id="operations-tab-system"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-system"
                            data-operations-live-group="system"
                            type="button"
                            role="tab"
                            aria-controls="operations-pane-system"
                            aria-selected="false"
                        >
                            System
                        </button>
                    </li>
                </ul>
            </div>

            <div class="card-body pt-3">
                <div class="tab-content" id="operations-dashboard-tab-content">
                    <div
                        class="tab-pane fade show active"
                        id="operations-pane-today"
                        role="tabpanel"
                        aria-labelledby="operations-tab-today"
                        tabindex="0"
                        data-operations-lazy-group="today"
                        data-operations-lazy-loaded="false"
                    >
                        <div id="operations-tab-today-content">
                            @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading support intelligence…'])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-team"
                        role="tabpanel"
                        aria-labelledby="operations-tab-team"
                        tabindex="0"
                        data-operations-lazy-group="team"
                        data-operations-lazy-loaded="false"
                    >
                        <div id="operations-tab-team-content">
                            @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading team availability…'])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-performance"
                        role="tabpanel"
                        aria-labelledby="operations-tab-performance"
                        tabindex="0"
                        data-operations-lazy-group="performance"
                        data-operations-lazy-loaded="false"
                    >
                        <div id="operations-tab-performance-content">
                            @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading performance metrics…'])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-system"
                        role="tabpanel"
                        aria-labelledby="operations-tab-system"
                        tabindex="0"
                        data-operations-lazy-group="system"
                        data-operations-lazy-loaded="false"
                    >
                        <div id="operations-tab-system-content">
                            @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading system health…'])
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="operations-ira-full-analysis-modal" tabindex="-1" aria-labelledby="operations-ira-full-analysis-modal-label" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="operations-ira-full-analysis-modal-label">Ira Full Analysis</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="operations-ira-full-analysis-modal-body">
                    @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading Ira analysis…'])
                </div>
            </div>
        </div>
    </div>
@endsection
