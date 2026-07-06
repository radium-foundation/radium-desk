@props([
    'dashboard',
])

<div class="operations-system-tab-content">
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

    <div id="operations-recent-ira-messages" class="mt-4">
        @include('admin.operations.partials.recent-ira-messages', ['messages' => $dashboard->recentIraMessages])
    </div>
</div>
