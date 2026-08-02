@props([
    'dashboard',
])

<div class="operations-system-tab-content">
    <div class="alert alert-light border mb-4" role="status">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong class="d-block">System &amp; Integration Health</strong>
                <span class="text-muted small">
                    Live platform and integration monitoring is on the Platform Dashboard. This tab keeps operational activity feeds.
                </span>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-primary">
                    Open Platform Dashboard
                </a>
                <a href="{{ route('admin.platform.index') }}#platform-zone-integration_health" class="btn btn-sm btn-outline-secondary">
                    Integration Health
                </a>
            </div>
        </div>
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

    <div id="operations-recent-ira-messages" class="mt-4">
        @include('admin.operations.partials.recent-ira-messages', ['messages' => $dashboard->recentIraMessages])
    </div>
</div>
