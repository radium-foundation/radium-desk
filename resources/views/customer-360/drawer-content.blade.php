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
        @include('customer-360.partials.executive-summary-placeholder', [
            'executiveSummaryUrl' => $executiveSummaryUrl ?? null,
        ])
        @include('customer-360.partials.health-card', ['healthCard' => $healthCard])
        @include('customer-360.partials.support-appointments', [
            'supportAppointments' => $supportAppointments ?? collect(),
            'incident' => $incident,
            'profilePhone' => $healthCard['phone'] ?? null,
        ])
        @include('customer-360.partials.waiting-state-card', ['waitingStateCard' => $waitingStateCard ?? null])
        @include('customer-360.partials.quick-actions', [
            'incident' => $incident,
            'order' => $order,
            'customer' => $customer,
            'device' => $device,
            'canRequestSerialNumber' => $canRequestSerialNumber ?? false,
            'canRequestCorrectSerial' => $canRequestCorrectSerial ?? false,
            'canCustomerNotResponding' => $canCustomerNotResponding ?? false,
            'canLinkOrder' => $canLinkOrder ?? false,
            'hideWorkflowActions' => $hideWorkflowActions ?? false,
            'hasRecommendedActions' => $hasRecommendedActions ?? false,
            'serialRequestState' => $serialRequestState ?? ['requested' => false, 'requested_at' => null, 'requested_at_label' => null],
            'correctSerialRequestState' => $correctSerialRequestState ?? ['requested' => false, 'requested_at' => null, 'requested_at_label' => null],
        ])
        @include('customer-360.partials.device-section', [
            'device' => $device,
            'sync_history' => $sync_history ?? [],
        ])
        @include('customer-360.partials.active-services', ['activeServices' => $activeServices])
    </div>

    <div id="customer-360-tab-timeline"
         class="customer-360-tab-pane d-none"
         role="tabpanel"
         data-customer-360-tab-pane="timeline">
        @include('customer-360.partials.timeline-tab-placeholder', [
            'timelineTabUrl' => $timelineTabUrl ?? null,
        ])
    </div>

    <div id="customer-360-tab-ai-assistant"
         class="customer-360-tab-pane d-none"
         role="tabpanel"
         data-customer-360-tab-pane="ai-assistant">
        @include('customer-360.partials.ai-tab-placeholder', [
            'aiTabUrl' => $aiTabUrl ?? null,
        ])
    </div>
</div>
