@extends('layouts.app')

@section('title', 'Operations Control Center')

@section('content')
    <div
        id="operations-dashboard-root"
        data-live-url="{{ route('admin.operations.live') }}"
        data-generated-at="{{ $dashboard->generatedAt->toIso8601String() }}"
        data-live-interval="{{ $operationsPollIntervalMs ?? 30000 }}"
        data-live-full-interval="{{ $operationsFullRefreshIntervalMs ?? 120000 }}"
        @can('automation-operations.view')
            data-automation-health-url="{{ route('admin.operations.automation-health') }}"
            data-automation-pipeline-url="{{ route('admin.automation.index') }}"
        @endcan
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

        @php
            $operationsHubTab = request()->query('hub_tab', 'today');
            $operationsHubActive = in_array($operationsHubTab, ['today', 'team', 'performance', 'system', 'automation'], true)
                ? $operationsHubTab
                : 'today';
        @endphp

        @include('navigation.mission-control-workspace-nav', [
            'active' => $operationsHubActive === 'automation' ? 'automation' : 'operations',
        ])

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
                        'members' => [],
                        'intelligence' => $dashboard->supportIntelligence,
                    ])
                </div>

                <div id="operations-queue-summary" class="operations-bento-cell operations-bento-cell--queue">
                    @include('admin.operations.partials.queue-summary-compact', [
                        'metrics' => $dashboard->queueMetrics,
                    ])
                </div>

                <div id="operations-active-operators" class="operations-bento-cell operations-bento-cell--operators">
                    @include('admin.operations.partials.active-operators-compact', [
                        'teamAvailability' => $dashboard->teamAvailability,
                    ])
                </div>

                <div id="operations-ira-briefing-compact" class="operations-bento-cell operations-bento-cell--ira">
                    @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading Ira insights…'])
                </div>
            </div>

            <div id="operations-health-status" class="operations-bento-row operations-bento-row--health">
                @include('admin.operations.partials.health-status-compact')
            </div>
        </section>

        <div id="operations-tabs-sentinel" class="operations-tabs-sentinel" aria-hidden="true"></div>

        <div class="operations-dashboard-tabs card border-0 shadow-sm operations-card-hover">
            <div class="card-header bg-white border-bottom-0 pb-0 operations-dashboard-tabs-header">
                @include('admin.operations.partials.hub-nav', [
                    'active' => $operationsHubActive,
                    'onControlCenter' => true,
                ])
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

                    @can('automation-operations.view')
                        <div
                            class="tab-pane fade"
                            id="operations-pane-automation"
                            role="tabpanel"
                            aria-labelledby="operations-tab-automation"
                            tabindex="0"
                            data-operations-lazy-group="automation"
                            data-operations-lazy-loaded="false"
                        >
                            <div id="operations-tab-automation-content">
                                @include('admin.operations.partials.automation-tab-shell')
                            </div>
                        </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @can('automation-operations.view')
        @include('admin.automation-health.partials.detail-drawer')
    @endcan

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

@push('vite')
    @vite('resources/js/pages/mission-control.js')
@endpush
