<section class="customer-360-section" data-customer-360-section="active-services" aria-labelledby="customer-360-services-heading">
    <h3 class="customer-360-section-title" id="customer-360-services-heading">Active services</h3>

    @if($activeServices === [])
        <p class="customer-360-empty-text mb-0">Not Available</p>
    @else
        <div class="customer-360-service-badges" role="list" aria-label="Active services">
            @foreach($activeServices as $service)
                <span @class([
                    'order-workspace-chip',
                    'customer-360-service-badge',
                    'order-workspace-chip--success' => ($service['variant'] ?? '') === 'success',
                    'order-workspace-chip--warning' => ($service['variant'] ?? '') === 'warning',
                    'order-workspace-chip--info' => ($service['variant'] ?? '') === 'info',
                    'order-workspace-chip--neutral' => ($service['variant'] ?? '') === 'neutral',
                ]) role="listitem">
                    <span class="customer-360-service-badge-label">{{ $service['label'] }}</span>
                    <span class="customer-360-service-badge-status">{{ $service['status'] }}</span>
                </span>
            @endforeach
        </div>
    @endif
</section>
