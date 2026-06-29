@php
    $displayValue = fn (?string $value) => filled($value) ? $value : 'Not Available';
@endphp

<section class="customer-360-section" data-customer-360-section="device" aria-labelledby="customer-360-device-heading">
    <h3 class="customer-360-section-title" id="customer-360-device-heading">Current Device</h3>
    <dl class="customer-360-dl">
        <div class="customer-360-dl-row">
            <dt>Model</dt>
            <dd>
                <span class="customer-360-device-model">{{ $displayValue($device['model_short'] ?? null) }}</span>
                @if(filled($device['model_canonical'] ?? null) && ($device['model_canonical'] ?? null) !== ($device['model_short'] ?? null))
                    <span class="customer-360-device-model-canonical">{{ $device['model_canonical'] }}</span>
                @elseif(filled($device['model_canonical'] ?? null) && blank($device['model_short'] ?? null))
                    <span class="customer-360-device-model-canonical">{{ $device['model_canonical'] }}</span>
                @endif
            </dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Serial Number</dt>
            <dd class="font-monospace">{{ $displayValue($device['serial_number'] ?? null) }}</dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Order ID</dt>
            <dd>{{ $displayValue($device['order_id'] ?? null) }}</dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Service Reference</dt>
            <dd>{{ $displayValue($device['service_reference'] ?? null) }}</dd>
        </div>
    </dl>
</section>
