@php
    $hardwareFulfilment = $hardwareFulfilment ?? null;
@endphp

@if(is_array($hardwareFulfilment) && isset($hardwareFulfilment['row']))
    @php
        $row = $hardwareFulfilment['row'];
        $ready = $hardwareFulfilment['ready'] ?? null;
        $shipment = $hardwareFulfilment['shipment'] ?? [];
        $isRin = $row->source === 'RIN' && ! $row->hasFulfilment;
        $canOperate = (bool) ($hardwareFulfilment['canOperate'] ?? false);
        $showPrimary = $row->mutatingAction
            && $canOperate
            && (
                ($row->hasFulfilment && ($hardwareFulfilment['showUrl'] ?? null))
                || ($row->nextAction === 'Open Fulfilment' && $row->supportOrderId)
            );
        $actionDialogUrl = $row->hasFulfilment && $row->fulfilmentId
            ? route('inventory.hardware-fulfilments.action-dialog', $row->fulfilmentId)
            : $row->awaitingActionDialogUrl();
        $details = $row->productDetails();
        $productText = $row->productMissing
            ? $row->productDisplay()
            : ($details !== []
                ? collect($details)->map(function (array $line): string {
                    $text = $line['label'];
                    if ($line['qty'] !== null) {
                        $text .= ' · Qty '.$line['qty'];
                    }

                    return $text;
                })->implode(', ')
                : $row->productDisplay());
    @endphp
    <section id="hardware-fulfilment"
             class="c360-section-card mb-3"
             data-customer-360-section="hardware-fulfilment"
             data-hardware-next-action="{{ $row->nextAction }}">
        <h2 class="h6 mb-2">Hardware</h2>
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <div>
                <div class="fw-semibold">{{ $row->sourceId }}</div>
                @if($row->customer !== '—')
                    <div class="text-muted small">{{ $row->customer }}</div>
                @endif
            </div>
            <span class="dashboard-hardware-status">{{ $row->operatorStatus() }}</span>
        </div>

        <dl class="c360-hardware-status small mb-2">
            <div class="c360-hardware-status__row">
                <dt>Product</dt>
                <dd @class(['text-danger' => $row->productMissing])>{{ $productText }}</dd>
            </div>
            <div class="c360-hardware-status__row">
                <dt>Serial</dt>
                <dd>
                    @if($row->allocatedSerials() !== [])
                        @include('inventory.hardware-fulfilments.fragments.serial-summary', [
                            'serials' => $row->allocatedSerials(),
                            'expected' => $row->expectedSerialQuantity,
                            'compact' => $row->serialDisplay(),
                            'id' => 'c360-hardware-serial-summary-'.($row->fulfilmentId ?? $row->sourceId),
                            'wrapperClass' => 'd-inline-block',
                        ])
                    @else
                        {{ $hardwareFulfilment['serialLabel'] ?? $row->serialStatusLabel() }}
                    @endif
                </dd>
            </div>
            <div class="c360-hardware-status__row">
                <dt>Fulfilment</dt>
                <dd>{{ $hardwareFulfilment['fulfilmentLabel'] ?? $row->fulfilmentStatusLabel() }}</dd>
            </div>
            <div class="c360-hardware-status__row">
                <dt>Shipment</dt>
                <dd>
                    <div>{{ $shipment['summary'] ?? 'Not shipped yet' }}</div>
                    @if(filled($shipment['awb'] ?? null))
                        <div class="text-muted">AWB: {{ $shipment['awb'] }}</div>
                    @endif
                    @if(filled($shipment['carrier'] ?? null))
                        <div class="text-muted">Carrier: {{ $shipment['carrier'] }}</div>
                    @endif
                    @if(filled($shipment['status'] ?? null) && ($shipment['status'] ?? '') !== ($shipment['summary'] ?? ''))
                        <div class="text-muted">Status: {{ $shipment['status'] }}</div>
                    @endif
                </dd>
            </div>
        </dl>

        <x-c360.customer-journey-tracker
            :milestones="$hardwareFulfilment['milestones']"
            :current-index="$hardwareFulfilment['currentIndex']"
        />
        @if($isRin)
            <p class="small mb-1 mt-3">Hardware cannot start yet.</p>
            <p class="small text-muted mb-2">Verified RIN → Desk hardware mapping is required.</p>
        @elseif($hardwareFulfilment['currentCaption'] ?? null)
            <p class="small text-muted mb-2 mt-3">{{ $hardwareFulfilment['currentCaption'] }}</p>
        @endif
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @if($showPrimary && $actionDialogUrl)
                <button type="button"
                        class="btn btn-sm btn-primary"
                        data-hardware-action-dialog="{{ $actionDialogUrl }}">
                    {{ $row->nextAction }}
                </button>
            @elseif($isRin)
                <span class="btn btn-sm btn-outline-primary">View</span>
            @endif
            @if($hardwareFulfilment['showUrl'] ?? null)
                <a class="btn btn-sm btn-outline-secondary" href="{{ $hardwareFulfilment['showUrl'] }}">Open Fulfilment</a>
            @endif
            @if(($hardwareFulfilment['canDownloadDocuments'] ?? false) && $ready && $row->fulfilmentId)
                @include('inventory.hardware-fulfilments.fragments.document-downloads', [
                    'fulfilment' => $row->fulfilmentId,
                    'ready' => $ready,
                    'wrapperClass' => 'mb-0',
                    'labelId' => 'c360-hardware-label-download',
                    'manifestId' => 'c360-hardware-manifest-download',
                ])
            @endif
        </div>
        @if($activity = ($hardwareFulfilment['activity'] ?? []))
            <ul class="list-unstyled small text-muted mb-0 mt-3">
                @foreach($activity as $item)
                    <li>{{ $item['done'] ? '✓' : '○' }} {{ $item['label'] }}</li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
