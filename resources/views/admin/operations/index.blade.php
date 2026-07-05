@extends('layouts.app')

@section('title', 'Operations Control Center')

@section('content')
    <div
        id="operations-dashboard-root"
        data-live-url="{{ route('admin.operations.live') }}"
        data-live-interval="30000"
    >
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
            <div>
                <h1 class="h3 mb-1">Operations Control Center</h1>
                <p class="text-muted mb-0">Executive operations dashboard for Radium Desk administrators.</p>
            </div>
            <div class="text-muted small" id="operations-dashboard-generated-at">
                Updated {{ display_app_datetime_seconds($dashboard->generatedAt) }}
            </div>
        </div>

        <div id="operations-ira-briefing" class="mb-3">
            @include('admin.operations.partials.ira-briefing', [
                'briefing' => $iraBriefing ?? null,
                'reasoningProvider' => $iraReasoningProvider ?? 'rule_based',
            ])
        </div>

        <div id="operations-overview-cards" class="mb-4">
            @include('admin.operations.partials.overview-cards', [
                'briefing' => $iraBriefing ?? null,
                'members' => $dashboard->teamAvailability,
                'insights' => $advisorInsights ?? [],
            ])
        </div>

        <div class="operations-dashboard-tabs card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom-0 pb-0">
                <ul class="nav nav-tabs card-header-tabs operations-dashboard-tablist flex-nowrap overflow-auto" id="operations-dashboard-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button
                            class="nav-link active"
                            id="operations-tab-today"
                            data-bs-toggle="tab"
                            data-bs-target="#operations-pane-today"
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
                    >
                        <div id="operations-ira-briefing-details" class="mb-4">
                            @include('admin.operations.partials.ira-briefing-details', ['briefing' => $iraBriefing ?? null])
                        </div>

                        <div id="operations-advisor-insights" class="mb-4">
                            @include('admin.operations.partials.advisor-insights', ['insights' => $advisorInsights ?? []])
                        </div>

                        <div id="operations-immediate-risks">
                            @include('admin.operations.partials.immediate-risks', ['briefing' => $iraBriefing ?? null])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-team"
                        role="tabpanel"
                        aria-labelledby="operations-tab-team"
                        tabindex="0"
                    >
                        <div id="operations-team-availability">
                            @include('admin.operations.partials.team-availability', ['members' => $dashboard->teamAvailability])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-performance"
                        role="tabpanel"
                        aria-labelledby="operations-tab-performance"
                        tabindex="0"
                    >
                        <div class="row g-4 mb-4">
                            <div class="col-lg-4">
                                <div id="operations-notification-metrics">
                                    @include('admin.operations.partials.notification-metrics', ['metrics' => $dashboard->notificationMetrics])
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div id="operations-automation-metrics">
                                    @include('admin.operations.partials.automation-metrics', ['metrics' => $dashboard->automationMetrics])
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div id="operations-queue-metrics">
                                    @include('admin.operations.partials.queue-metrics', ['metrics' => $dashboard->queueMetrics])
                                </div>
                            </div>
                        </div>

                        <div id="operations-radiumbox-health">
                            @include('admin.operations.partials.radiumbox-health', ['health' => $dashboard->radiumBoxHealth])
                        </div>
                    </div>

                    <div
                        class="tab-pane fade"
                        id="operations-pane-system"
                        role="tabpanel"
                        aria-labelledby="operations-tab-system"
                        tabindex="0"
                    >
                        <div id="operations-system-health" class="mb-4">
                            @include('admin.operations.partials.system-health', ['components' => $dashboard->systemHealth])
                        </div>

                        <div id="operations-integration-health" class="mb-4">
                            @include('admin.operations.partials.integration-health', ['cards' => $dashboard->integrationHealth])
                        </div>

                        <div class="row g-4">
                            <div class="col-xl-6">
                                <div id="operations-recent-notification-failures">
                                    @include('admin.operations.partials.recent-notification-failures', ['failures' => $dashboard->recentNotificationFailures])
                                </div>
                            </div>
                            <div class="col-xl-6">
                                <div id="operations-recent-automation-activity">
                                    @include('admin.operations.partials.recent-automation-activity', ['activities' => $dashboard->recentAutomationActivity])
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
