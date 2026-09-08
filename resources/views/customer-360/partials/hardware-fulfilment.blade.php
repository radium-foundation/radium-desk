@php
    $hardwareFulfilment = $hardwareFulfilment ?? null;
@endphp

@if(is_array($hardwareFulfilment) && isset($hardwareFulfilment['row']))
    @php
        $row = $hardwareFulfilment['row'];
        $ready = $hardwareFulfilment['ready'] ?? null;
        $activity = $hardwareFulfilment['activity'] ?? [];
        $isRin = $row->source === 'RIN' && ! $row->hasFulfilment;
        $showPrimary = $row->hasFulfilment && ($hardwareFulfilment['showUrl'] ?? null) && $row->mutatingAction;
    @endphp
    <section id="hardware-fulfilment"
             class="c360-section-card mb-3"
             data-customer-360-section="hardware-fulfilment">
        <h2 class="h6 mb-2">Hardware Fulfilment</h2>
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div>
                <div class="fw-semibold">{{ $row->sourceId }}</div>
                <div class="text-muted small">{{ $row->payment }} · {{ $row->productDisplay() }}</div>
                @if($row->serialDisplay() !== '—')
                    <div class="text-muted small">Serial {{ $row->serialDisplay() }}</div>
                @endif
            </div>
            <span class="dashboard-hardware-status">{{ $row->operatorStatus() }}</span>
        </div>
        <x-c360.customer-journey-tracker
            :milestones="$hardwareFulfilment['milestones']"
            :current-index="$hardwareFulfilment['currentIndex']"
        />
        @if($ready && (filled($ready->courier) || filled($ready->awb)))
            <dl class="row small mb-2 mt-3">
                @if(filled($ready->courier))
                    <dt class="col-4">Courier</dt>
                    <dd class="col-8 mb-1">{{ $ready->courier }}</dd>
                @endif
                @if(filled($ready->awb))
                    <dt class="col-4">AWB</dt>
                    <dd class="col-8 mb-1">{{ $ready->awb }}</dd>
                @endif
            </dl>
        @endif
        @if($isRin)
            <p class="small mb-1 mt-3">Hardware cannot start yet.</p>
            <p class="small text-muted mb-2">Verified RIN → Desk hardware mapping is required.</p>
        @elseif($hardwareFulfilment['currentCaption'] ?? null)
            <p class="small text-muted mb-2 mt-3">{{ $hardwareFulfilment['currentCaption'] }}</p>
        @endif
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($showPrimary)
                <a class="btn btn-sm btn-primary" href="{{ $row->primaryUrl() }}">{{ $row->nextAction }}</a>
            @endif
            @if($hardwareFulfilment['showUrl'] ?? null)
                <a class="btn btn-sm btn-outline-secondary" href="{{ $hardwareFulfilment['showUrl'] }}">Open Fulfilment</a>
            @endif
        </div>
        @if($activity !== [])
            <ul class="list-unstyled small text-muted mb-0 mt-3">
                @foreach($activity as $item)
                    <li>{{ $item['done'] ? '✓' : '○' }} {{ $item['label'] }}</li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
