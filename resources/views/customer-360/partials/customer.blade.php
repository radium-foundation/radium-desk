@php
    $displayValue = fn (?string $value) => filled($value) ? $value : 'Not Available';
@endphp

<section class="customer-360-section" data-customer-360-section="customer" aria-labelledby="customer-360-customer-heading">
    <h3 class="customer-360-section-title" id="customer-360-customer-heading">Customer</h3>
    <dl class="customer-360-dl">
        <div class="customer-360-dl-row">
            <dt>Name</dt>
            <dd>{{ $displayValue($customer['name'] ?? null) }}</dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Mobile</dt>
            <dd>{{ $displayValue($customer['mobile'] ?? null) }}</dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>Email</dt>
            <dd>{{ $displayValue($customer['email'] ?? null) }}</dd>
        </div>
        <div class="customer-360-dl-row">
            <dt>City</dt>
            <dd>{{ $displayValue($customer['city'] ?? null) }}</dd>
        </div>
    </dl>
</section>
