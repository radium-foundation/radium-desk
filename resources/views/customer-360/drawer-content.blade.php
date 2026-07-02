<div class="customer-360-drawer-content" data-customer-360-content>
    <nav class="customer-360-tabs" aria-label="Customer 360 sections">
        <ul class="nav nav-pills customer-360-tab-list" role="tablist">
            <li class="nav-item" role="presentation">
                <button type="button"
                        class="nav-link active"
                        role="tab"
                        aria-selected="true"
                        aria-controls="customer-360-tab-overview"
                        data-customer-360-tab="overview">
                    <i class="bi bi-grid" aria-hidden="true"></i>
                    <span>Overview</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button type="button"
                        class="nav-link"
                        role="tab"
                        aria-selected="false"
                        aria-controls="customer-360-tab-timeline"
                        data-customer-360-tab="timeline">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                    <span>Timeline</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button type="button"
                        class="nav-link"
                        role="tab"
                        aria-selected="false"
                        aria-controls="customer-360-tab-ai-assistant"
                        data-customer-360-tab="ai-assistant">
                    <i class="bi bi-stars" aria-hidden="true"></i>
                    <span>IRA AI</span>
                </button>
            </li>
        </ul>
    </nav>

    <div id="customer-360-tab-overview"
         class="customer-360-tab-pane"
         role="tabpanel"
         data-customer-360-tab-pane="overview">
        @include('customer-360.partials.executive-summary', [
            'incident' => $incident,
            'executiveSummary' => $executiveSummary ?? null,
        ])
        @include('customer-360.partials.health-card', ['healthCard' => $healthCard])
        @include('customer-360.partials.waiting-state-card', ['waitingStateCard' => $waitingStateCard ?? null])
        @include('customer-360.partials.quick-actions', [
            'incident' => $incident,
            'order' => $order,
            'customer' => $customer,
            'device' => $device,
            'canRequestSerialNumber' => $canRequestSerialNumber ?? false,
        ])
        @include('customer-360.partials.current-device', ['device' => $device])
        @include('customer-360.partials.active-services', ['activeServices' => $activeServices])
    </div>

    <div id="customer-360-tab-timeline"
         class="customer-360-tab-pane d-none"
         role="tabpanel"
         data-customer-360-tab-pane="timeline">
        @include('customer-360.partials.timeline', [
            'timeline' => $timeline,
            'timelineLoadMoreUrl' => $timelineLoadMoreUrl ?? null,
        ])
    </div>

    <div id="customer-360-tab-ai-assistant"
         class="customer-360-tab-pane d-none"
         role="tabpanel"
         data-customer-360-tab-pane="ai-assistant">
        @include('customer-360.partials.ai-advisor', ['insights' => $operationsAdvisorInsights ?? []])
        @include('customer-360.partials.ai-assistant', ['aiAssistant' => $aiAssistant])
        @include('customer-360.partials.ai-workbench', [
            'workbench' => $aiWorkbench,
            'incident' => $incident,
        ])
    </div>
</div>
