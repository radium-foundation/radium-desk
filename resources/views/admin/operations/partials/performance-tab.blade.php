@props([
    'dashboard',
])

<div class="operations-performance-tab-content">
    <div class="alert alert-light border mb-4" role="status">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong class="d-block">Platform monitoring moved</strong>
                <span class="text-muted small">
                    Integration health (RadiumBox, Cashfree, Gmail) and platform diagnostics live on the Platform Dashboard.
                </span>
            </div>
            <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-primary">
                Open Platform Dashboard
            </a>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12">
            <div id="operations-ivr-health">
                @include('admin.operations.partials.ivr-health', [
                    'health' => $dashboard->ivrAnalytics['ivr_health'] ?? [],
                ])
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div id="operations-ivr-agent-performance">
                @include('admin.operations.partials.ivr-agent-performance', [
                    'agents' => $dashboard->ivrAnalytics['agent_performance'] ?? [],
                ])
            </div>
        </div>
        <div class="col-lg-6">
            <div id="operations-ivr-missed-calls">
                @include('admin.operations.partials.ivr-missed-calls', [
                    'calls' => $dashboard->ivrAnalytics['missed_calls'] ?? [],
                ])
            </div>
        </div>
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

    <div id="operations-cashfree-device-enrichment-quality" class="mt-4">
        @include('admin.operations.partials.cashfree-device-enrichment-quality', [
            'quality' => $dashboard->cashfreeDeviceEnrichmentQuality,
        ])
    </div>

    <div id="operations-missing-serial-automation-quality" class="mt-4">
        @include('admin.operations.partials.missing-serial-automation-quality', [
            'quality' => $dashboard->missingSerialAutomationQuality,
        ])
    </div>
</div>
