@php
    $phone = trim((string) ($customer['mobile'] ?? $healthCard['phone'] ?? ''));
    $email = trim((string) ($customer['email'] ?? $healthCard['email'] ?? ''));
    $serial = $device['serial_number'] ?? ($order?->serial_number);
    $customerName = filled($healthCard['name'] ?? null)
        ? $healthCard['name']
        : ($order?->customer_name);
    $referenceNumber = $order?->transaction_id ?? $incident->display_reference;
    $orderId = $order?->order_id;

    $paletteActions = [
        ['id' => 'open-order', 'label' => 'Open Order', 'icon' => 'bi-box-arrow-up-right', 'type' => 'link', 'href' => $order ? route('orders.show', $order) : null, 'keywords' => ['order']],
        ['id' => 'open-case', 'label' => 'Open Case', 'icon' => 'bi-folder2-open', 'type' => 'link', 'href' => route('incidents.show', $incident), 'keywords' => ['case', 'incident']],
        ['id' => 'correct-customer', 'label' => 'Correct Customer', 'icon' => 'bi-person-gear', 'type' => 'trigger', 'trigger' => 'correct-customer-details', 'enabled' => (bool) ($correctCustomerDetailsEligibility['allowed'] ?? false), 'disabledReason' => $correctCustomerDetailsEligibility['reason'] ?? 'Action is not available.', 'keywords' => ['customer', 'details']],
        ['id' => 'correct-serial', 'label' => 'Correct Serial', 'icon' => 'bi-upc', 'type' => 'trigger', 'trigger' => 'correct-serial-number', 'enabled' => (bool) ($correctSerialNumberEligibility['allowed'] ?? false), 'disabledReason' => $correctSerialNumberEligibility['reason'] ?? 'Action is not available.', 'keywords' => ['serial']],
        ['id' => 'schedule-appointment', 'label' => 'Schedule Appointment', 'icon' => 'bi-calendar-event', 'type' => 'tab', 'tab' => 'overview', 'anchor' => 'support-appointments', 'keywords' => ['appointment', 'schedule']],
        ['id' => 'request-correct-serial', 'label' => 'Request Correct Serial', 'icon' => 'bi-camera', 'type' => 'trigger', 'trigger' => 'request-correct-serial', 'enabled' => (bool) ($canRequestCorrectSerial ?? false), 'keywords' => ['serial', 'request']],
        ['id' => 'refund', 'label' => 'Refund', 'icon' => 'bi-arrow-counterclockwise', 'type' => 'link', 'href' => route('refunds.create'), 'keywords' => ['refund']],
    ];

    $paletteActions = array_values(array_filter($paletteActions, function (array $action): bool {
        if (($action['type'] ?? '') === 'link') {
            return filled($action['href'] ?? null);
        }

        if (($action['type'] ?? '') === 'trigger') {
            if (in_array($action['id'] ?? '', ['correct-customer', 'correct-serial'], true)) {
                return (bool) ($showIdentityCorrectionActions ?? false);
            }

            return (bool) ($action['enabled'] ?? true);
        }

        return true;
    }));

    $searchIndex = [
        'incidentId' => $incident->id,
        'sc' => $incident->display_reference,
        'reference' => $referenceNumber,
        'orderId' => $orderId,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'serial' => filled($serial) ? $serial : null,
        'customerName' => filled($customerName) ? $customerName : null,
        'actions' => $paletteActions,
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
        :canRequestSerialNumber="$canRequestSerialNumber ?? false"
        :canRequestCorrectSerial="$canRequestCorrectSerial ?? false"
        :canCustomerNotResponding="$canCustomerNotResponding ?? false"
        :canLinkOrder="$canLinkOrder ?? false"
        :canCorrectCustomerDetails="$canCorrectCustomerDetails ?? false"
        :canCorrectSerialNumber="$canCorrectSerialNumber ?? false"
        :correctCustomerDetailsEligibility="$correctCustomerDetailsEligibility ?? ['allowed' => false, 'reason' => null]"
        :correctSerialNumberEligibility="$correctSerialNumberEligibility ?? ['allowed' => false, 'reason' => null]"
        :showIdentityCorrectionActions="$showIdentityCorrectionActions ?? false"
        :hideWorkflowActions="$hideWorkflowActions ?? false"
        :hasRecommendedActions="$hasRecommendedActions ?? false"
        :serialRequestState="$serialRequestState ?? ['requested' => false]"
        :correctSerialRequestState="$correctSerialRequestState ?? ['requested' => false]"
        :supportAppointments="$supportAppointments ?? collect()"
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
