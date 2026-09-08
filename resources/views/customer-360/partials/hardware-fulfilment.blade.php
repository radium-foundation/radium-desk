@php
    $hardwareFulfilment = $hardwareFulfilment ?? null;
@endphp

@if(is_array($hardwareFulfilment) && isset($hardwareFulfilment['row']))
    @php
        $row = $hardwareFulfilment['row'];
    @endphp
    <section id="hardware-fulfilment"
             class="c360-section-card mb-3"
             data-customer-360-section="hardware-fulfilment">
        <h2 class="h6 mb-2">Hardware Fulfilment</h2>
        <x-c360.customer-journey-tracker
            :milestones="$hardwareFulfilment['milestones']"
            :current-index="$hardwareFulfilment['currentIndex']"
        />
        <dl class="row small mb-2 mt-3">
            <dt class="col-4">Source</dt>
            <dd class="col-8 mb-1">{{ $row->source }} · {{ $row->sourceId }}</dd>
            <dt class="col-4">Payment</dt>
            <dd class="col-8 mb-1">{{ $row->payment }}</dd>
            <dt class="col-4">Product</dt>
            <dd class="col-8 mb-1">{{ $row->product }}</dd>
            <dt class="col-4">Serial</dt>
            <dd class="col-8 mb-1">{{ $row->serialStatus }}</dd>
            <dt class="col-4">Invoice</dt>
            <dd class="col-8 mb-1">{{ $row->invoiceStatus }}</dd>
            <dt class="col-4">Shipment</dt>
            <dd class="col-8 mb-1">{{ $row->shipmentStatus }}</dd>
            <dt class="col-4">AWB</dt>
            <dd class="col-8 mb-1">{{ $row->awbStatus }}</dd>
            <dt class="col-4">Stage</dt>
            <dd class="col-8 mb-1">{{ $row->stage->label() }}</dd>
        </dl>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($row->hasFulfilment && $hardwareFulfilment['showUrl'])
                <a class="btn btn-sm btn-primary" href="{{ $hardwareFulfilment['showUrl'] }}">{{ $row->nextAction }}</a>
            @elseif(! $row->hasFulfilment)
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Start Hardware Fulfilment</button>
            @endif
            @if($row->openOrderUrl())
                <a class="btn btn-sm btn-outline-secondary" href="{{ $row->openOrderUrl() }}">Open order</a>
            @endif
        </div>
        @if($hardwareFulfilment['startBlocker'] ?? $row->blocker)
            <p class="small text-muted mb-0 mt-2">{{ $hardwareFulfilment['startBlocker'] ?? $row->blocker }}</p>
        @endif
    </section>
@endif
