@extends('layouts.app')

@section('title', $canAllocate ? 'Allocate hardware serial' : 'Hardware fulfilment')

@php
    $order = $fulfilment->commerceOrder;
    $requiredQty = (int) collect($requirements)->sum('qty');
    $allocatedQty = (int) collect($requirements)->sum('allocated_qty');
    $remainingQty = max(0, $requiredQty - $allocatedQty);
    $serialsComplete = $requiredQty > 0 && $remainingQty === 0;
    if ($serialsComplete) {
        $qtyLabel = $requiredQty === 1 ? 'Serial allocated' : 'Serials allocated';
    } elseif ($remainingQty === $requiredQty) {
        $qtyLabel = $requiredQty === 1 ? '1 serial required' : $requiredQty.' serials required';
    } else {
        $qtyLabel = $remainingQty === 1 ? '1 serial still needed' : $remainingQty.' serials still needed';
    }
    $stateValue = $fulfilment->state?->value ?? 'unknown';
    $stateLabel = strtoupper(str_replace('_', ' ', $stateValue));
    $allocatedBranch = $derivedBranch?->code
        ?? $allocated->first()?->inventorySerial?->branch?->code;
    $canViewInvoice = $canViewInvoice ?? false;
    $invoiceShowUrl = $invoiceShowUrl ?? null;
    $invoicePdfUrl = $invoicePdfUrl ?? null;
@endphp

@section('content')
    <style>
        .hf-alloc { max-width: 40rem; }
        .hf-alloc-kicker { letter-spacing: .08em; }
        .hf-alloc-card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .06);
            border-radius: .75rem;
            padding: 1.25rem 1.35rem;
        }
        .hf-alloc-meta { gap: .75rem 1.25rem; }
        .hf-alloc-meta dt {
            font-size: .7rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: #868e96;
            margin: 0 0 .15rem;
        }
        .hf-alloc-meta dd { margin: 0; font-weight: 600; }
        .hf-alloc-status {
            display: inline-flex;
            align-items: center;
            padding: .2rem .65rem;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .03em;
            background: #eef2f6;
            color: #343a40;
        }
        .hf-alloc-status.is-ready { background: #e7f5ff; color: #0b5ed7; }
        .hf-alloc-status.is-done { background: #d3f9d8; color: #2b8a3e; }
        .hf-alloc-qty { font-size: 1.05rem; font-weight: 650; }
        .hf-alloc-results { display: grid; gap: .4rem; }
        .hf-alloc-serial {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            width: 100%;
            text-align: left;
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: .55rem;
            background: #fff;
            padding: .65rem .8rem;
        }
        .hf-alloc-serial:hover,
        .hf-alloc-serial:focus-visible { border-color: #0d6efd; }
        .hf-alloc-serial.is-selected { border-color: #0d6efd; background: #f8fbff; }
        .hf-alloc-serial small { color: #868e96; }
        .hf-alloc-confirm { background: #f8f9fa; }
        .hf-alloc-confirm dl,
        dl.hf-alloc-confirm { display: grid; grid-template-columns: 7.5rem 1fr; gap: .35rem .75rem; margin: 0; }
        .hf-alloc-confirm dt,
        dl.hf-alloc-confirm dt { color: #868e96; font-weight: 500; }
        .hf-alloc-confirm dd,
        dl.hf-alloc-confirm dd { margin: 0; font-weight: 600; }
        .hf-ship-blockers { margin: 0; padding-left: 1.1rem; }
    </style>

    <div class="hf-alloc">
        <p class="hf-alloc-kicker text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-2">{{ $canAllocate ? 'Allocate serial' : 'Hardware fulfilment' }}</h1>
        <p class="text-muted mb-3">{{ $qtyLabel }}. Physical branch and pickup come from the allocated stock serial.</p>

        @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

        @if(isset($opsRow, $stepperMilestones, $stepperCurrentIndex))
            <div class="hf-alloc-card mb-3" id="hardware-progress">
                <x-c360.customer-journey-tracker
                    :milestones="$stepperMilestones"
                    :current-index="$stepperCurrentIndex"
                />
                <div class="mt-3">
                    <p class="text-muted small text-uppercase fw-semibold mb-1">Current step</p>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="hf-alloc-status is-ready">{{ $opsRow->operatorStatus() }}</span>
                        @if($opsRow->primaryUrl() && $opsRow->mutatingAction)
                            <a class="btn btn-sm btn-primary" href="{{ $opsRow->primaryUrl() }}">{{ $opsRow->nextAction }}</a>
                        @else
                            <span class="fw-semibold">{{ $opsRow->nextAction }}</span>
                        @endif
                    </div>
                    @if($stepperCurrentCaption ?? null)
                        <p class="small text-muted mb-0 mt-2">{{ $stepperCurrentCaption }}</p>
                    @endif
                    @if($opsRow->blocker)
                        <p class="small text-danger mb-0 mt-2">{{ $opsRow->blocker }}</p>
                    @endif
                </div>
            </div>
        @endif

        @if(isset($opsRow) && $opsRow->nextAction === 'Ready for Fulfilment')
            <div class="hf-alloc-card mb-3" id="hardware-mark-ready">
                <p class="text-muted small text-uppercase fw-semibold mb-2">Readiness</p>
                <p class="small mb-3">This order is ingested. Mark it ready for fulfilment before allocating serials.</p>
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.ready.store', $fulfilment) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Ready for Fulfilment</button>
                </form>
            </div>
        @endif

        @if(session('status'))
            <div class="alert alert-success py-2">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger py-2" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="hf-alloc-card mb-3">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Fulfilment</p>
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="fs-5 fw-semibold">{{ $fulfilment->source_id }}</div>
                    <div class="text-muted small">
                        @if($order?->order_no)
                            {{ $order->order_no }}
                        @endif
                    </div>
                </div>
                <span @class([
                    'hf-alloc-status',
                    'is-ready' => in_array($stateValue, ['ready_for_fulfilment', 'invoice_issued'], true),
                    'is-done' => in_array($stateValue, ['serials_allocated', 'shipment_created', 'awb_assigned', 'shipped'], true),
                ])>{{ $stateLabel }}</span>
            </div>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Order</dt>
                <dd>{{ $fulfilment->source_id }}</dd>
                <dt>Support case</dt>
                <dd>{{ $fulfilment->support_order_id ?: '—' }}</dd>
                <dt>Fulfilment ID</dt>
                <dd>{{ $fulfilment->id }}</dd>
                <dt>Branch</dt>
                <dd>{{ $allocatedBranch ?? 'Derived from selected serial' }}</dd>
            </dl>
        </div>

        @foreach($requirements as $line)
            @php
                $lineAllocatedQty = (int) ($line['allocated_qty'] ?? 0);
                $lineQty = (int) $line['qty'];
                $lineSerials = $line['allocated_serials'] ?? [];
                $lineComplete = $lineQty > 0 && $lineAllocatedQty >= $lineQty;
                $linePartial = $lineAllocatedQty > 0 && $lineAllocatedQty < $lineQty;
                if ($lineComplete) {
                    $lineSerialStatus = 'Allocated';
                } elseif ($linePartial) {
                    $lineSerialStatus = 'Partial ('.$lineAllocatedQty.' of '.$lineQty.')';
                } else {
                    $lineSerialStatus = 'Not allocated';
                }
            @endphp
            <div
                class="hf-alloc-card mb-3"
                data-item-id="{{ $line['commerce_order_item_id'] }}"
                data-qty="{{ $line['qty'] }}"
                data-product="{{ $line['description'] }}"
                data-sku="{{ $line['inventory_sku'] ?? $line['sku'] ?? '' }}"
            >
                <p class="text-muted small text-uppercase fw-semibold mb-2">Product</p>
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <div class="fs-5 fw-semibold">{{ $line['description'] }}</div>
                        <div class="text-muted small">
                            SKU {{ $line['inventory_sku'] ?? $line['sku'] ?? 'unset' }}
                            @if($line['model_id']) · model {{ $line['model_id'] }} @endif
                            @if($line['catalog_sku']) · {{ $line['catalog_sku'] }} @endif
                            @if($line['rdserviceid']) · bundled RD #{{ $line['rdserviceid'] }} @endif
                        </div>
                    </div>
                    <span @class([
                        'hf-alloc-status',
                        'is-done' => $lineComplete,
                        'is-ready' => $linePartial,
                    ])>{{ $lineSerialStatus }}</span>
                </div>
                <dl class="hf-alloc-confirm mb-0">
                    <dt>Quantity</dt>
                    <dd>{{ $lineQty }}</dd>
                    <dt>Serial</dt>
                    <dd>
                        @include('inventory.hardware-fulfilments.fragments.serial-summary', [
                            'serials' => $lineSerials,
                            'expected' => $lineQty,
                            'id' => 'hardware-show-line-serial-'.$line['commerce_order_item_id'],
                        ])
                    </dd>
                    <dt>Serial status</dt>
                    <dd>{{ $lineSerialStatus }}</dd>
                </dl>
                @if($canAllocate && ! $lineComplete)
                    <p class="hf-alloc-qty mt-2 mb-1">{{ $lineQty === 1 ? '1 serial required' : $lineQty.' serials required' }}</p>
                    <p class="text-muted small mb-0">
                        Available {{ $line['available_qty'] }}
                        · Delhi {{ $line['available_by_branch']['DELHI-RETAIL'] ?? 0 }}
                        · Mumbai {{ $line['available_by_branch']['MUMBAI'] ?? 0 }}
                    </p>
                @endif
                @if(! $line['map_ready'])
                    <p class="text-danger small mb-0 mt-2">Owner SKU map is missing for this model_id. Allocation is blocked.</p>
                @endif
            </div>
        @endforeach

        <div class="hf-alloc-card mb-3" id="hardware-invoice">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Invoice</p>
            @if($shipment->invoice)
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="fs-5 fw-semibold">{{ $shipment->invoice }}</div>
                    @if($canViewInvoice && $invoiceShowUrl)
                        <a href="{{ $invoiceShowUrl }}" id="hardware-invoice-view">View invoice</a>
                    @endif
                    @if($canViewInvoice && $invoicePdfUrl)
                        <a href="{{ $invoicePdfUrl }}" id="hardware-invoice-pdf">GST PDF</a>
                    @endif
                </div>
            @else
                <p class="mb-2">Not issued</p>
                @if($canIssueInvoice ?? false)
                    <form method="POST" action="{{ route('inventory.hardware-fulfilments.invoice.store', $fulfilment) }}" id="hardware-invoice-issue-form">
                        @csrf
                        <button type="submit" class="btn btn-primary" data-confirm="Issue the hardware GST invoice for this one order?">Issue Hardware Invoice</button>
                    </form>
                @endif
            @endif
        </div>

        @if($canAllocate)
            <form method="POST" action="{{ route('inventory.hardware-fulfilments.serials.store', $fulfilment) }}" id="hardware-serial-allocate-form">
                @csrf
                @foreach($requirements as $line)
                    <div class="hf-alloc-card mb-3" data-picker-for="{{ $line['commerce_order_item_id'] }}">
                        <label class="form-label mb-2" for="serial-search-{{ $line['commerce_order_item_id'] }}">Search available serials</label>
                        <input
                            id="serial-search-{{ $line['commerce_order_item_id'] }}"
                            type="search"
                            class="form-control js-serial-query"
                            placeholder="Serial number"
                            autocomplete="off"
                            data-item-id="{{ $line['commerce_order_item_id'] }}"
                        >
                        <div class="js-serial-results hf-alloc-results mt-3" aria-live="polite"></div>
                        <ul class="js-serial-selected list-unstyled mb-0 mt-3"></ul>
                    </div>
                @endforeach

                <div class="hf-alloc-card hf-alloc-confirm mb-3 d-none" id="hardware-serial-confirm" hidden>
                    <p class="text-muted small text-uppercase fw-semibold mb-2">Confirm allocation</p>
                    <dl id="hardware-serial-confirm-rows"></dl>
                </div>
                <p class="text-danger small d-none" id="hardware-serial-client-error"></p>
                <button type="submit" class="btn btn-primary" id="hardware-serial-submit" disabled>Allocate Serial</button>
            </form>
        @endif

        <div class="hf-alloc-card mb-3" id="hardware-shipment">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Shipping readiness</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Payment</dt>
                <dd>{{ $shipment->payment }}</dd>
                <dt>Pickup location</dt>
                <dd>
                    @if($shipment->pickupBranch || $shipment->pickupLocation)
                        {{ $shipment->pickupBranch ?? '—' }}{{ $shipment->pickupLocation ? ' · '.$shipment->pickupLocation : '' }}
                    @else
                        Not derived yet
                    @endif
                </dd>
                <dt>Shipping address</dt>
                <dd>{{ $shipment->shipTo ?? 'Incomplete' }}</dd>
                <dt>Country</dt>
                <dd>{{ $shipment->country ?: 'India' }}</dd>
                <dt>Parcel</dt>
                <dd>
                    @if($shipment->parcel)
                        {{ $shipment->parcel }}
                        @if($shipment->actualWeight || $shipment->volumetricWeight)
                            <div class="text-muted small">
                                @if($shipment->actualWeight)Actual Weight: {{ $shipment->actualWeight }}@endif
                                @if($shipment->actualWeight && $shipment->volumetricWeight) · @endif
                                @if($shipment->volumetricWeight)Volumetric Weight: {{ $shipment->volumetricWeight }}@endif
                            </div>
                        @endif
                    @else
                        Not attached
                    @endif
                </dd>
                <dt>Shipment</dt>
                <dd>{{ $shipment->status }}</dd>
                <dt>Courier</dt>
                <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
                <dt>AWB</dt>
                <dd>{{ $shipment->awb ?? 'Not assigned' }}</dd>
            </dl>

            @if($shipment->providerRejection)
                <p class="text-danger small mt-3 mb-0">{{ $shipment->providerRejection }}</p>
                <p class="text-muted small mb-0">Courier is selected. Shipment creation was attempted and the provider rejected it. No provider shipment, AWB, or label exists.</p>
            @endif

            @if($shipment->blockers !== [])
                <ul class="hf-ship-blockers text-danger small mt-3 mb-0">
                    @foreach($shipment->blockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif

            @if($shipment->canAttachSnapshot)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.parcel-snapshot.store', $fulfilment) }}" id="hardware-parcel-snapshot-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">
                        Attach the verified packaging for this product.
                        @if($shipment->catalogPackaging)
                            {{ $shipment->catalogPackaging }}.
                        @endif
                        This does not change the order.
                    </p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-parcel-snapshot-submit">Attach verified packaging</button>
                </form>
            @endif
            @if($shipment->canAttachMeasuredParcel)
                <div class="mt-3" id="hardware-parcel-measure">
                    @include('inventory.hardware-fulfilments.fragments.action-measure-parcel', [
                        'ajax' => false,
                        'formId' => 'hardware-show-measured-parcel-form',
                    ])
                </div>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-courier">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Courier</p>
            @if($shipment->recommendationNote !== '')
                <p class="small mb-2 {{ $shipment->recommendationReturned ? 'text-success' : 'text-muted' }}">{{ $shipment->recommendationNote }}</p>
            @endif
            <dl class="hf-alloc-confirm mb-0">
                <dt>Payment mode</dt>
                <dd>{{ $shipment->collectionModeLabel }}</dd>
                <dt>Selected courier</dt>
                <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
            </dl>
            @if($shipment->canFetchCourierOptions)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.courier-options.store', $fulfilment) }}" id="hardware-courier-options-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Requests current courier options from Shiprocket using the verified pickup postcode, delivery pincode, and parcel weight. Options expire and must be fetched again if shipment inputs change.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-courier-options-submit">Get Courier Options</button>
                </form>
            @endif
            @if($shipment->canSelectCourier)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.courier.store', $fulfilment) }}" id="hardware-courier-select-form" class="mt-3">
                    @csrf
                    <label class="form-label" for="hardware-courier-id">Courier / service</label>
                    <select class="form-select" id="hardware-courier-id" name="courier_id" required>
                        <option value="">Select a returned courier</option>
                        @foreach($shipment->courierOptions as $option)
                            @php
                                $optionLabel = $option['courier_name'] ?? $option['courier_id'];
                                if (! empty($option['courier_type'])) {
                                    $optionLabel .= ' · '.$option['courier_type'];
                                }
                                if (! empty($option['mode'])) {
                                    $optionLabel .= ' · '.$option['mode'];
                                }
                                if ($option['rate'] !== null) {
                                    $optionLabel .= ' · '.$option['rate'];
                                }
                                if (! empty($option['estimated_delivery'])) {
                                    $optionLabel .= ' · '.$option['estimated_delivery'];
                                }
                                $optionLabel .= ' · '.$shipment->collectionModeLabel;
                                if (! empty($option['provider_recommended'])) {
                                    $optionLabel .= ' · Shiprocket Recommended';
                                }
                            @endphp
                            <option value="{{ $option['courier_id'] }}" @selected($shipment->selectedCourierId === $option['courier_id'])>
                                {{ $optionLabel }}
                            </option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-outline-primary mt-3" id="hardware-courier-select-submit">Select Courier</button>
                </form>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-shipment-create">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Shipment</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Status</dt>
                <dd>{{ $shipment->status }}</dd>
                <dt>Provider shipment</dt>
                <dd>{{ $shipment->providerShipmentId ?? '—' }}</dd>
            </dl>
            @if($shipment->canCreate)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.shipment.store', $fulfilment) }}" id="hardware-shipment-form" class="mt-3">
                    @csrf
                    <div class="hf-alloc-confirm mb-3">
                        <p class="text-muted small text-uppercase fw-semibold mb-2">Confirm shipment</p>
                        <dl>
                            <dt>Order</dt>
                            <dd>{{ $shipment->order }}</dd>
                            <dt>Product</dt>
                            <dd>{{ $shipment->product ?? '—' }}</dd>
                            <dt>Serial</dt>
                            <dd>
                                @include('inventory.hardware-fulfilments.fragments.serial-summary', [
                                    'serials' => $shipment->serials,
                                    'expected' => $shipment->quantity,
                                    'id' => 'hardware-show-shipment-serials',
                                ])
                            </dd>
                            <dt>Invoice</dt>
                            <dd>
                                {{ $shipment->invoice }}
                                @if($canViewInvoice && $invoiceShowUrl)
                                    · <a href="{{ $invoiceShowUrl }}">View invoice</a>
                                @endif
                            </dd>
                            <dt>Pickup location</dt>
                            <dd>{{ $shipment->pickupLocation }}</dd>
                            <dt>Ship-to</dt>
                            <dd>{{ $shipment->shipTo }}</dd>
                            <dt>Parcel</dt>
                            <dd>{{ $shipment->parcel }}</dd>
                            <dt>Courier</dt>
                            <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
                            <dt>Payment mode</dt>
                            <dd>{{ $shipment->collectionModeLabel }}</dd>
                            <dt>Provider</dt>
                            <dd>{{ $shipment->provider }}</dd>
                        </dl>
                    </div>
                    <button type="submit" class="btn btn-primary" id="hardware-shipment-submit">{{ $shipment->actionLabel }}</button>
                </form>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-awb">
            <p class="text-muted small text-uppercase fw-semibold mb-2">AWB</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>AWB</dt>
                <dd>{{ $shipment->awb ?? 'Not assigned' }}</dd>
                <dt>Courier</dt>
                <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
            </dl>
            @if($shipment->canAssignAwb)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.awb.store', $fulfilment) }}" id="hardware-awb-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Assigns an AWB with the selected Shiprocket courier. The AWB is not invented.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-awb-submit">Assign AWB</button>
                </form>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-label">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Shipping label</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Label</dt>
                <dd>{{ $shipment->labelStatus }}</dd>
            </dl>
            @if($shipment->canGenerateLabel)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.label.store', $fulfilment) }}" id="hardware-label-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Generates the official Shiprocket shipping label. Print and paste it on the parcel.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-label-submit">Generate Shipping Label</button>
                </form>
            @endif
            @if($shipment->labelUrl)
                <p class="mt-3 mb-0">
                    <a href="{{ route('inventory.hardware-fulfilments.label.download', $fulfilment) }}" id="hardware-label-download">Download Label</a>
                </p>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-package-evidence">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Package Photo</p>
            @if($shipment->packagePhotoRecorded())
                <p class="mb-2">✓ Recorded</p>
                @if($shipment->packagePhotoId())
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.hardware-fulfilments.package-evidence.show', [$fulfilment, $shipment->packagePhotoId()]) }}">View Photo</a>
                @endif
                @if($shipment->canUploadPackageBeforeLabel)
                    <details class="mt-2">
                        <summary class="small text-muted">Replace</summary>
                        <form method="POST" action="{{ route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment) }}" id="hardware-package-before-form" class="mt-2" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="kind" value="package_before_label">
                            <input class="form-control" type="file" id="hardware-package-before-photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary mt-2" id="hardware-package-before-submit">Replace</button>
                        </form>
                    </details>
                @endif
            @else
                <p class="mb-2">Package photo pending</p>
                @if($shipment->canUploadPackageBeforeLabel)
                    <form method="POST" action="{{ route('inventory.hardware-fulfilments.package-evidence.store', $fulfilment) }}" id="hardware-package-before-form" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="kind" value="package_before_label">
                        <label class="form-label" for="hardware-package-before-photo">Upload Package Photo</label>
                        <input class="form-control" type="file" id="hardware-package-before-photo" name="photo" accept="image/jpeg,image/png,image/webp" required>
                        <p class="text-muted small mt-2 mb-2">Evidence can be added after shipment or pickup. It does not block shipping.</p>
                        <button type="submit" class="btn btn-outline-primary" id="hardware-package-before-submit">Upload Package Photo</button>
                    </form>
                @endif
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-manifest-pickup">
            <p class="text-muted small text-uppercase fw-semibold mb-2">Manifest / Pickup</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Pickup</dt>
                <dd>
                    @if($shipment->pickupStatus === 'Requested')
                        Pickup Requested
                        @if($shipment->pickupRequestedAt)
                            <span class="text-muted"> · {{ $shipment->pickupRequestedAt }}</span>
                        @endif
                    @else
                        Not requested
                    @endif
                </dd>
                <dt>Manifest</dt>
                <dd>{{ $shipment->manifestStatus === 'Available' ? 'Manifest generated' : 'Manifest not generated' }}</dd>
                <dt>Ready for pickup</dt>
                <dd>{{ $shipment->readyForPickup ? 'Yes' : 'Not marked' }}</dd>
            </dl>
            @if($shipment->canRequestPickup)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.pickup.store', $fulfilment) }}" id="hardware-pickup-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Requests pickup from Shiprocket for this shipment. This does not mark the order dispatched.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-pickup-submit">Request Pickup</button>
                </form>
            @elseif($shipment->pickupStatus === 'Requested')
                <p class="mt-3 mb-0" id="hardware-pickup-requested-status">Pickup Requested</p>
            @endif
            @if($shipment->canGenerateManifest)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.manifest.store', $fulfilment) }}" id="hardware-manifest-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Generates a Shiprocket manifest for this shipment after pickup is requested. Manifests can include more than one shipment at the provider.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-manifest-submit">Generate Manifest</button>
                </form>
            @endif
            @if($shipment->manifestUrl)
                <p class="mt-3 mb-0">
                    <a href="{{ route('inventory.hardware-fulfilments.manifest.download', $fulfilment) }}" id="hardware-manifest-download">Download Manifest</a>
                </p>
            @endif
            @if($shipment->canMarkReadyForPickup)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.ready-for-pickup.store', $fulfilment) }}" id="hardware-ready-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Marks this fulfilment ready for pickup after pickup is requested. Package photo is not required.</p>
                    <button type="submit" class="btn btn-primary" id="hardware-ready-submit">Mark Ready for Pickup</button>
                </form>
            @endif
        </div>

        <div class="hf-alloc-card mb-3" id="hardware-shipment-details">
            <details>
                <summary class="text-muted small">Details</summary>
                <dl class="hf-alloc-confirm mt-2 mb-0">
                    <dt>Customer</dt>
                    <dd>{{ $shipment->customer ?: '—' }}</dd>
                    <dt>Phone</dt>
                    <dd>{{ $shipment->phone ?: '—' }}</dd>
                    <dt>Email</dt>
                    <dd>{{ $shipment->email ?: '—' }}</dd>
                    <dt>Verified packaging</dt>
                    <dd>
                        @if($shipment->catalogPackaging)
                            {{ $shipment->catalogPackaging }}{{ $shipment->catalogVerified ? ' (verified)' : '' }}
                        @else
                            Unknown until serials are allocated
                        @endif
                    </dd>
                    <dt>Shipment no</dt>
                    <dd>{{ $shipment->shipmentNo ?? '—' }}</dd>
                    <dt>Provider shipment</dt>
                    <dd>{{ $shipment->providerShipmentId ?? '—' }}</dd>
                    <dt>Manifest id</dt>
                    <dd>{{ $shipment->manifestId ?? '—' }}</dd>
                </dl>
                @if($boundShipment?->events?->isNotEmpty())
                    <div class="mt-3">
                        <p class="text-muted small text-uppercase fw-semibold mb-2">Shipment events</p>
                        <ul class="small mb-0 ps-3">
                            @foreach($boundShipment->events as $event)
                                <li>
                                    {{ $event->activity }}
                                    @if($event->awb)
                                        · AWB {{ $event->awb }}
                                    @endif
                                    @if($event->external_shipment_id)
                                        · {{ $event->external_shipment_id }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </details>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const form = document.getElementById('hardware-serial-allocate-form');
            if (!form) {
                return;
            }

            const searchUrl = @json(route('inventory.hardware-fulfilments.serials.search', $fulfilment));
            const submit = document.getElementById('hardware-serial-submit');
            const confirmBox = document.getElementById('hardware-serial-confirm');
            const confirmRows = document.getElementById('hardware-serial-confirm-rows');
            const clientError = document.getElementById('hardware-serial-client-error');
            const pickers = [];

            function escapeHtml(value) {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }

            function lineCard(itemId) {
                return document.querySelector('[data-item-id="' + itemId + '"]');
            }

            function renderSelected(picker) {
                picker.selected.innerHTML = picker.chosen.map(function (row) {
                    return '<li class="small mb-1">'
                        + escapeHtml(row.serial_number)
                        + ' · '
                        + escapeHtml(row.branch_code || 'unknown branch')
                        + '<input type="hidden" name="serials[' + picker.itemId + '][]" value="'
                        + escapeHtml(row.serial_number)
                        + '"></li>';
                }).join('');
            }

            function uniqueBranches() {
                const codes = {};
                pickers.forEach(function (picker) {
                    picker.chosen.forEach(function (row) {
                        if (row.branch_code) {
                            codes[row.branch_code] = true;
                        }
                    });
                });
                return Object.keys(codes);
            }

            function selectionComplete() {
                return pickers.every(function (picker) {
                    return picker.chosen.length === picker.qty;
                });
            }

            function updateConfirm() {
                const branches = uniqueBranches();
                const mixed = branches.length > 1;
                const ready = selectionComplete() && !mixed;

                if (clientError) {
                    clientError.classList.toggle('d-none', !mixed);
                    clientError.textContent = mixed
                        ? 'Selected serials are at more than one physical branch. Choose serials from one location.'
                        : '';
                }

                if (!confirmBox || !confirmRows || !submit) {
                    return;
                }

                confirmBox.classList.toggle('d-none', !ready);
                confirmBox.hidden = !ready;
                submit.disabled = !ready;

                if (!ready) {
                    confirmRows.innerHTML = '';
                    return;
                }

                confirmRows.innerHTML = pickers.map(function (picker) {
                    const card = lineCard(picker.itemId);
                    const product = card ? card.getAttribute('data-product') : '';
                    const sku = card ? card.getAttribute('data-sku') : '';
                    return picker.chosen.map(function (row) {
                        return '<dt>Product</dt><dd>' + escapeHtml(product) + '</dd>'
                            + '<dt>SKU</dt><dd>' + escapeHtml(sku) + '</dd>'
                            + '<dt>Serial</dt><dd>' + escapeHtml(row.serial_number) + '</dd>'
                            + '<dt>Physical branch</dt><dd>' + escapeHtml(row.branch_code || '') + '</dd>'
                            + '<dt>Quantity</dt><dd>1</dd>';
                    }).join('');
                }).join('');
            }

            function renderResults(picker, serials) {
                picker.results.innerHTML = '';
                if (!serials.length) {
                    picker.results.textContent = 'No available serials.';
                    return;
                }

                serials.forEach(function (row) {
                    const selected = picker.chosen.some(function (item) {
                        return item.serial_number === row.serial_number;
                    });
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'hf-alloc-serial' + (selected ? ' is-selected' : '');
                    button.innerHTML = '<span><strong>' + escapeHtml(row.serial_number) + '</strong></span>'
                        + '<small>' + escapeHtml(row.branch_code || '') + '</small>';
                    button.addEventListener('click', function () {
                        if (selected) {
                            picker.chosen = picker.chosen.filter(function (item) {
                                return item.serial_number !== row.serial_number;
                            });
                        } else if (picker.chosen.length < picker.qty) {
                            picker.chosen.push({
                                serial_number: row.serial_number,
                                branch_code: row.branch_code || '',
                            });
                        }
                        renderSelected(picker);
                        renderResults(picker, serials);
                        updateConfirm();
                    });
                    picker.results.appendChild(button);
                });
            }

            function search(picker) {
                const params = new URLSearchParams({
                    commerce_order_item_id: String(picker.itemId),
                    q: picker.input.value || '',
                });

                fetch(searchUrl + '?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
                }).then(function (result) {
                    if (!result.ok) {
                        const errors = result.payload.errors || {};
                        picker.results.textContent = (errors.branch || errors.serials || [result.payload.message || 'Search failed.'])[0];
                        return;
                    }
                    renderResults(picker, result.payload.serials || []);
                }).catch(function () {
                    picker.results.textContent = 'Search failed.';
                });
            }

            document.querySelectorAll('[data-picker-for]').forEach(function (card) {
                const itemId = card.getAttribute('data-picker-for');
                const line = lineCard(itemId);
                const picker = {
                    itemId: itemId,
                    qty: Number(line ? line.getAttribute('data-qty') : '0'),
                    input: card.querySelector('.js-serial-query'),
                    results: card.querySelector('.js-serial-results'),
                    selected: card.querySelector('.js-serial-selected'),
                    chosen: [],
                    timer: null,
                };
                pickers.push(picker);
                picker.input.addEventListener('input', function () {
                    window.clearTimeout(picker.timer);
                    picker.timer = window.setTimeout(function () {
                        search(picker);
                    }, 250);
                });
                search(picker);
            });

            form.addEventListener('submit', function (event) {
                if (!selectionComplete() || uniqueBranches().length > 1 || submit.disabled) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Allocating…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-invoice-issue-form');
            if (!form) {
                return;
            }
            form.addEventListener('submit', function (event) {
                if (!window.confirm('Issue the hardware GST invoice for this one order?')) {
                    event.preventDefault();
                }
            });
        })();

        (function () {
            const form = document.getElementById('hardware-shipment-form');
            const submit = document.getElementById('hardware-shipment-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Create this Shiprocket shipment once with the derived pickup, serial, invoice, address, parcel, and selected courier shown above?')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Creating shipment…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-courier-options-form');
            const submit = document.getElementById('hardware-courier-options-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Request current courier options from Shiprocket for this fulfilment?')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Requesting couriers…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-courier-select-form');
            const submit = document.getElementById('hardware-courier-select-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Use this Shiprocket courier for the subsequent shipment create?')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Selecting courier…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-awb-form');
            const submit = document.getElementById('hardware-awb-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Assign an AWB with the selected Shiprocket courier? The AWB will not be invented.')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Assigning AWB…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-parcel-snapshot-form');
            const submit = document.getElementById('hardware-parcel-snapshot-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Attach the verified packaging to this fulfilment? The order will not be changed.')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Attaching…';
            });
        })();

        [
            ['hardware-label-form', 'hardware-label-submit', 'Generate the official Shiprocket shipping label?', 'Generating label…'],
            ['hardware-pickup-form', 'hardware-pickup-submit', 'Request Shiprocket pickup for this shipment?', 'Requesting pickup…'],
            ['hardware-manifest-form', 'hardware-manifest-submit', 'Generate the Shiprocket manifest for this shipment?', 'Generating manifest…'],
            ['hardware-ready-form', 'hardware-ready-submit', 'Mark this fulfilment ready for pickup?', 'Saving…'],
            ['hardware-package-before-form', 'hardware-package-before-submit', 'Record this package photo? It does not block shipping.', 'Saving photo…'],
        ].forEach(function (row) {
            const form = document.getElementById(row[0]);
            const submit = document.getElementById(row[1]);
            if (!form || !submit) {
                return;
            }
            form.addEventListener('submit', function (event) {
                if (!window.confirm(row[2])) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = row[3];
            });
        });
    </script>
@endpush
