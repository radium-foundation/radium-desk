@props([
    'quality' => [],
])

@php
    $missing = (int) ($quality['paid_orders_missing_device_info'] ?? 0);
    $recovered = (int) ($quality['recovered_from_radiumbox'] ?? 0);
    $needContact = (int) ($quality['need_customer_contact'] ?? 0);
@endphp

<section class="mb-4" aria-labelledby="cashfree-device-enrichment-quality-heading">
    <h2 id="cashfree-device-enrichment-quality-heading" class="h5 mb-3">Data Quality / Ira</h2>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-7">Paid orders missing device info</dt>
                <dd class="col-sm-5 text-end mb-2">{{ number_format($missing) }}</dd>

                <dt class="col-sm-7">Recovered from RadiumBox</dt>
                <dd class="col-sm-5 text-end mb-2">{{ number_format($recovered) }}</dd>

                <dt class="col-sm-7">Need customer contact</dt>
                <dd class="col-sm-5 text-end mb-0">{{ number_format($needContact) }}</dd>
            </dl>
        </div>
    </div>
</section>
