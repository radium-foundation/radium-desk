@php
    /** @var \App\Services\HardwareFulfilment\Data\HardwareShipmentOperationalVisibility $shipmentVisibility */
@endphp
@if($shipmentVisibility->showPanel())
    <div class="hf-alloc-card mb-3" id="hardware-shipment-operations">
        <p class="text-muted small text-uppercase fw-semibold mb-2">Shipment operations</p>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span @class([
                'hf-alloc-status',
                'is-done' => ! $shipmentVisibility->blocked && ! $shipmentVisibility->partialSuccess,
            ])>{{ $shipmentVisibility->lifecycleHeadline }}</span>
            @if($shipmentVisibility->blocked)
                <span class="badge text-bg-danger">Blocked</span>
            @elseif($shipmentVisibility->partialSuccess)
                <span class="badge text-bg-warning">Partial</span>
            @endif
        </div>
        <p class="small mb-2">{{ $shipmentVisibility->lifecycleDetail }}</p>
        @if($shipmentVisibility->blockedReason)
            <p class="small text-danger mb-2">{{ $shipmentVisibility->blockedReason }}</p>
        @endif
        @if($shipmentVisibility->partialSuccess && $shipmentVisibility->partialSuccessNote)
            <p class="small text-warning mb-2">{{ $shipmentVisibility->partialSuccessNote }}</p>
        @endif

        <dl class="hf-alloc-confirm mb-0">
            <dt>Desk shipment status</dt>
            <dd>{{ $shipmentVisibility->deskShipmentStatus }}</dd>
            <dt>Shiprocket merchant ref</dt>
            <dd>
                {{ $shipmentVisibility->shiprocketMerchantReference }}
                @if($shipmentVisibility->merchantReferenceIsLegacyHw && $shipmentVisibility->canonicalMerchantReference)
                    <span class="text-muted small">(legacy HW- prefix; new orders use {{ $shipmentVisibility->canonicalMerchantReference }})</span>
                @endif
            </dd>
            <dt>Shiprocket shipment id</dt>
            <dd>{{ $shipmentVisibility->externalShipmentId ?? '—' }}</dd>
            <dt>Shiprocket order id</dt>
            <dd>{{ $shipmentVisibility->externalOrderId ?? '—' }}</dd>
            <dt>AWB</dt>
            <dd>{{ $shipmentVisibility->awb ?? 'Not assigned' }}</dd>
            <dt>Courier</dt>
            <dd>{{ $shipmentVisibility->courier ?? '—' }}</dd>
            <dt>Shiprocket track</dt>
            <dd>{{ $shipmentVisibility->shiprocketTrackStatus }}</dd>
            <dt>Normalized movement</dt>
            <dd>{{ $shipmentVisibility->shiprocketTrackNormalized }}</dd>
            <dt>Courier movement</dt>
            <dd>{{ $shipmentVisibility->courierMovementLabel }}</dd>
            <dt>Provider status code</dt>
            <dd>{{ $shipmentVisibility->providerStatusCode }}</dd>
            @if($shipmentVisibility->sanitizedProviderRejection)
                <dt>Provider rejection</dt>
                <dd>{{ $shipmentVisibility->sanitizedProviderRejection }}</dd>
            @endif
            <dt>Last track refresh</dt>
            <dd>
                {{ $shipmentVisibility->lastProviderTrackAt }}
                <span @class([
                    'text-muted',
                    'text-warning' => $shipmentVisibility->trackFreshness === 'stale',
                    'text-danger' => $shipmentVisibility->trackFreshness === 'missing',
                ])>
                    @if($shipmentVisibility->trackFreshness === 'stale')
                        · stale
                    @elseif($shipmentVisibility->trackFreshness === 'missing')
                        · missing
                    @endif
                </span>
            </dd>
            <dt>Last Shiprocket API (audit)</dt>
            <dd>{{ $shipmentVisibility->lastProviderApiAt }}</dd>
            <dt>Label</dt>
            <dd>{{ $shipmentVisibility->labelAvailability }}</dd>
            <dt>Desk pickup evidence</dt>
            <dd>{{ $shipmentVisibility->deskPickupEvidence }}</dd>
        </dl>
        @if($shipmentVisibility->pickupEvidenceNote !== '')
            <p class="small text-muted mt-2 mb-0">{{ $shipmentVisibility->pickupEvidenceNote }}</p>
        @endif

        @if($shipmentVisibility->apiAuditRows !== [])
            <div class="mt-3">
                <p class="text-muted small text-uppercase fw-semibold mb-2">Shiprocket API audit (sanitized)</p>
                <ul class="small mb-0 ps-3">
                    @foreach($shipmentVisibility->apiAuditRows as $row)
                        <li>
                            {{ $row->recordedAtIst }}
                            · {{ $row->detailLine }}
                            @if($row->providerMessage)
                                <span class="text-muted"> — {{ $row->providerMessage }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif