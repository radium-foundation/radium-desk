<div class="customer-360-drawer-content" data-customer-360-content>
    @include('customer-360.partials.health-card', ['healthCard' => $healthCard])
    @include('customer-360.partials.quick-actions', [
        'incident' => $incident,
        'order' => $order,
        'customer' => $customer,
        'device' => $device,
    ])
    @include('customer-360.partials.current-device', ['device' => $device])
    @include('customer-360.partials.active-services', ['activeServices' => $activeServices])
    @include('customer-360.partials.timeline', [
        'timeline' => $timeline,
        'timelineLoadMoreUrl' => $timelineLoadMoreUrl ?? null,
    ])
</div>
