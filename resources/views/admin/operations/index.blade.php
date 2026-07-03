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
                <p class="text-muted mb-0">Real-time operational view of Radium Desk for administrators.</p>
            </div>
            <div class="text-muted small" id="operations-dashboard-generated-at">
                Updated {{ display_app_datetime_seconds($dashboard->generatedAt) }}
            </div>
        </div>

        <div id="operations-advisor-insights" class="mb-4">
            @include('admin.operations.partials.advisor-insights', ['insights' => $advisorInsights ?? []])
        </div>

        <div id="operations-system-health">
            @include('admin.operations.partials.system-health', ['components' => $dashboard->systemHealth])
        </div>

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

        <div id="operations-radiumbox-health" class="mb-4">
            @include('admin.operations.partials.radiumbox-health', ['health' => $dashboard->radiumBoxHealth])
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
@endsection
