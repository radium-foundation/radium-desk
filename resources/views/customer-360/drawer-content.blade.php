@php
    $phone = trim((string) ($customer['mobile'] ?? $healthCard['phone'] ?? ''));
    $email = trim((string) ($customer['email'] ?? $healthCard['email'] ?? ''));
    $serial = $device['serial_number'] ?? ($order?->serial_number);
    $customerName = filled($healthCard['name'] ?? null)
        ? $healthCard['name']
        : ($order?->customer_name);
    $referenceNumber = $order?->transaction_id ?? $incident->display_reference;
    $orderId = $order?->order_id;

    $searchPaletteActions = array_values($paletteActions ?? []);

    $searchIndex = [
        'incidentId' => $incident->id,
        'sc' => $incident->display_reference,
        'reference' => $referenceNumber,
        'orderId' => $orderId,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'serial' => filled($serial) ? $serial : null,
        'customerName' => filled($customerName) ? $customerName : null,
        'actions' => $searchPaletteActions,
    ];
@endphp

<div class="customer-360-drawer-content c360-cockpit"
     data-customer-360-content
     data-c360-search-index="{{ json_encode($searchIndex, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
    <x-c360.operations-header
        :incident="$incident"
        :order="$order"
        :device="$device"
        :healthCard="$healthCard"
        :summary="$summary ?? []"
        :isWaitingForCustomer="$isWaitingForCustomer ?? false"
        :waitingStateCard="$waitingStateCard ?? null"
    />

    <x-c360.quick-action-toolbar
        :incident="$incident"
        :order="$order"
        :customer="$customer"
        :overflowMenuGroups="$overflowMenuGroups ?? []"
    />

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
        @include('customer-360.partials.health-card', [
            'healthCard' => $healthCard,
            'activeServices' => $activeServices ?? [],
            'summary' => $summary ?? [],
        ])
        @include('customer-360.partials.communication-actions', [
            'communicationActionStatuses' => $communicationActionStatuses ?? [],
            'incident' => $incident,
        ])
        @include('customer-360.partials.waiting-state-card', ['waitingStateCard' => $waitingStateCard ?? null])
        @include('customer-360.partials.support-appointments', [
            'supportAppointments' => $supportAppointments ?? collect(),
            'incident' => $incident,
            'profilePhone' => $healthCard['phone'] ?? null,
        ])
        @include('customer-360.partials.device-section', [
            'device' => $device,
            'sync_history' => $sync_history ?? [],
            'activeServices' => $activeServices ?? [],
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
