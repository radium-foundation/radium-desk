@extends('layouts.app')

@section('title', $canAllocate ? 'Allocate hardware serial' : 'Hardware fulfilment')

@php
    $order = $fulfilment->commerceOrder;
    $requiredQty = collect($requirements)->sum('qty');
    $qtyLabel = $requiredQty === 1 ? '1 serial required' : $requiredQty.' serials required';
    $stateValue = $fulfilment->state?->value ?? 'unknown';
    $stateLabel = strtoupper(str_replace('_', ' ', $stateValue));
    $allocatedBranch = $derivedBranch?->code
        ?? $allocated->first()?->inventorySerial?->branch?->code;
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
        .hf-alloc-confirm dl { display: grid; grid-template-columns: 7.5rem 1fr; gap: .35rem .75rem; margin: 0; }
        .hf-alloc-confirm dt { color: #868e96; font-weight: 500; }
        .hf-alloc-confirm dd { margin: 0; font-weight: 600; }
        .hf-ship-blockers { margin: 0; padding-left: 1.1rem; }
    </style>

    <div class="hf-alloc">
        <p class="hf-alloc-kicker text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-2">{{ $canAllocate ? 'Allocate serial' : 'Hardware fulfilment' }}</h1>
        <p class="text-muted mb-3">{{ $qtyLabel }}. Physical branch and pickup come from the allocated stock serial.</p>

        @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

        @if(session('status'))
            <div class="alert alert-success py-2">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger py-2" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="hf-alloc-card mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <div class="fs-5 fw-semibold">{{ $fulfilment->source_id }}</div>
                    <div class="text-muted small">
                        @if($order?->order_no)
                            {{ $order->order_no }}
                        @endif
                        @if($fulfilment->support_order_id)
                            · Support {{ $fulfilment->support_order_id }}
                        @endif
                    </div>
                </div>
                <span @class([
                    'hf-alloc-status',
                    'is-ready' => in_array($stateValue, ['ready_for_fulfilment', 'invoice_issued'], true),
                    'is-done' => in_array($stateValue, ['serials_allocated', 'shipment_created', 'awb_assigned', 'shipped'], true),
                ])>{{ $stateLabel }}</span>
            </div>
            <dl class="hf-alloc-meta d-flex flex-wrap mb-0">
                <div>
                    <dt>Fulfilment</dt>
                    <dd>{{ $fulfilment->id }}</dd>
                </div>
                <div>
                    <dt>Physical branch</dt>
                    <dd>{{ $allocatedBranch ?? 'Derived from selected serial' }}</dd>
                </div>
            </dl>
        </div>

        @if($allocated->isNotEmpty())
            <div class="hf-alloc-card mb-3">
                <p class="text-muted small text-uppercase fw-semibold mb-2">Allocated</p>
                @foreach($allocated as $serial)
                    <div class="d-flex justify-content-between gap-3 mb-2">
                        <div>
                            <div class="fw-semibold">{{ $serial->serial_number }}</div>
                            <div class="text-muted small">
                                {{ $serial->inventorySerial?->branch?->code ?? $allocatedBranch ?? 'stock location recorded' }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @foreach($requirements as $line)
            <div
                class="hf-alloc-card mb-3"
                data-item-id="{{ $line['commerce_order_item_id'] }}"
                data-qty="{{ $line['qty'] }}"
                data-product="{{ $line['description'] }}"
                data-sku="{{ $line['inventory_sku'] ?? $line['sku'] ?? '' }}"
            >
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
                    <div class="hf-alloc-qty">{{ $line['qty'] === 1 ? '1 serial required' : $line['qty'].' serials required' }}</div>
                </div>
                <p class="text-muted small mb-0">
                    Available {{ $line['available_qty'] }}
                    · Delhi {{ $line['available_by_branch']['DELHI-RETAIL'] ?? 0 }}
                    · Mumbai {{ $line['available_by_branch']['MUMBAI'] ?? 0 }}
                    · Delhi / DELHI-RETAIL
                    · Mumbai / MUMBAI
                </p>
                @if(! $line['map_ready'])
                    <p class="text-danger small mb-0 mt-2">Owner SKU map is missing for this model_id. Allocation is blocked.</p>
                @endif
            </div>
        @endforeach

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
            <p class="text-muted small text-uppercase fw-semibold mb-2">Shipment</p>
            <dl class="hf-alloc-confirm mb-0">
                <dt>Status</dt>
                <dd>{{ $shipment->status }}</dd>
                <dt>Customer</dt>
                <dd>{{ $shipment->customer ?: '—' }}</dd>
                <dt>Phone</dt>
                <dd>{{ $shipment->phone ?: '—' }}</dd>
                <dt>Email</dt>
                <dd>{{ $shipment->email ?: '—' }}</dd>
                <dt>Product</dt>
                <dd>{{ $shipment->product ?? '—' }}</dd>
                <dt>Quantity</dt>
                <dd>{{ $shipment->quantity ?? '—' }}</dd>
                <dt>Payment</dt>
                <dd>{{ $shipment->payment }}</dd>
                <dt>Pickup branch</dt>
                <dd>{{ $shipment->pickupBranch ?? 'Not derived yet' }}</dd>
                <dt>Pickup location</dt>
                <dd>{{ $shipment->pickupLocation ?? 'Not derived yet' }}</dd>
                <dt>Ship-to</dt>
                <dd>{{ $shipment->shipTo ?? 'Incomplete' }}</dd>
                <dt>Country</dt>
                <dd>
                    @if($shipment->countryMissing)
                        Missing — not inferred
                    @else
                        Present{{ $shipment->country ? ' — '.$shipment->country : '' }}
                    @endif
                </dd>
                <dt>Parcel</dt>
                <dd>{{ $shipment->parcel ?? 'Unavailable — not persisted' }}</dd>
                <dt>Parcel source</dt>
                <dd>
                    @if($shipment->parcelSource === 'ingest')
                        Ingest
                    @elseif($shipment->parcelSource === 'snapshot')
                        Fulfilment snapshot
                    @else
                        Unavailable
                    @endif
                </dd>
                <dt>Fulfilment parcel snapshot</dt>
                <dd>
                    @if(is_array($fulfilment->parcel_snapshot) && $fulfilment->parcel_snapshot !== [])
                        {{ $fulfilment->parcel_snapshot['weight'] ?? '—' }}
                        {{ $fulfilment->parcel_snapshot['weight_unit'] ?? 'kg' }}
                        ·
                        {{ $fulfilment->parcel_snapshot['length'] ?? '—' }}×{{ $fulfilment->parcel_snapshot['breadth'] ?? '—' }}×{{ $fulfilment->parcel_snapshot['height'] ?? '—' }}
                        {{ $fulfilment->parcel_snapshot['dimension_unit'] ?? 'cm' }}
                    @else
                        None
                    @endif
                </dd>
                <dt>Catalog pack</dt>
                <dd>
                    {{ $shipment->catalogPackaging ?? 'Unknown until serials are allocated' }}
                    @if($shipment->catalogPackaging)
                        · catalog / {{ $shipment->catalogVerified ? 'verified' : 'not verified' }}
                    @endif
                </dd>
                <dt>Invoice</dt>
                <dd>{{ $shipment->invoice ?? 'Not issued' }}</dd>
                <dt>Serial</dt>
                <dd>{{ $shipment->serials === [] ? 'Not allocated' : implode(', ', $shipment->serials) }}</dd>
                <dt>Shiprocket state</dt>
                <dd>{{ $shipment->alreadyCreated ? $shipment->provider : $shipment->provider.' (not called)' }}</dd>
                <dt>Shipment ID</dt>
                <dd>{{ $shipment->shipmentId ?? 'Not created' }}</dd>
                <dt>Shipment no</dt>
                <dd>{{ $shipment->shipmentNo ?? '—' }}</dd>
                <dt>Provider shipment</dt>
                <dd>{{ $shipment->providerShipmentId ?? '—' }}</dd>
                <dt>Courier</dt>
                <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
                <dt>AWB</dt>
                <dd>{{ $shipment->awb ?? 'Not assigned' }}</dd>
            </dl>

            @if($shipment->blockers !== [])
                <ul class="hf-ship-blockers text-danger small mt-3 mb-0">
                    @foreach($shipment->blockers as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif

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

            @if($shipment->canAttachSnapshot)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.parcel-snapshot.store', $fulfilment) }}" id="hardware-parcel-snapshot-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Copies the verified catalog pack onto this fulfilment. It does not change the order parcel.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-parcel-snapshot-submit">Attach parcel snapshot</button>
                </form>
            @endif

            @if($canCorrectCountry && $shipment->canCorrectCountry)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.country.store', $fulfilment) }}" id="hardware-country-form" class="mt-3">
                    @csrf
                    <label class="form-label" for="hardware-country">Shipping country</label>
                    <input type="text" name="country" id="hardware-country" class="form-control" maxlength="64" required autocomplete="off">
                    <p class="text-muted small mt-1 mb-2">Fill-if-absent overlay only. Type the country. It is not inferred.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-country-submit">Record country</button>
                </form>
            @endif

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
                    <p class="text-muted small text-uppercase fw-semibold mb-2">Courier options</p>
                    @if($shipment->recommendationNote !== '')
                        <p class="small mb-2 {{ $shipment->recommendationReturned ? 'text-success' : 'text-muted' }}">{{ $shipment->recommendationNote }}</p>
                    @endif
                    <div class="d-grid gap-2">
                        @foreach($shipment->courierOptions as $option)
                            <label class="hf-alloc-serial mb-0">
                                <span>
                                    <input
                                        type="radio"
                                        name="courier_id"
                                        value="{{ $option['courier_id'] }}"
                                        @checked($shipment->selectedCourierId === $option['courier_id'])
                                        required
                                    >
                                    <strong>{{ $option['courier_name'] ?? $option['courier_id'] }}</strong>
                                    <span class="text-muted"> · {{ $option['courier_id'] }}</span>
                                    @if($option['provider_recommended'])
                                        <span class="hf-alloc-status is-ready ms-1">Shiprocket Recommended</span>
                                    @endif
                                    @if($option['rate'] !== null)
                                        <div class="small text-muted">Rate {{ $option['rate'] }}</div>
                                    @endif
                                    @if($option['estimated_delivery'] !== null)
                                        <div class="small text-muted">Estimated delivery {{ $option['estimated_delivery'] }}</div>
                                    @endif
                                    @if($option['cod_available'] !== null || $option['prepaid_available'] !== null)
                                        <div class="small text-muted">
                                            @if($option['prepaid_available'] !== null)
                                                Prepaid {{ $option['prepaid_available'] ? 'yes' : 'no' }}
                                            @endif
                                            @if($option['cod_available'] !== null)
                                                · COD {{ $option['cod_available'] ? 'yes' : 'no' }}
                                            @endif
                                        </div>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <button type="submit" class="btn btn-outline-primary mt-3" id="hardware-courier-select-submit">Select Courier</button>
                </form>
            @endif

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
                            <dd>{{ implode(', ', $shipment->serials) }}</dd>
                            <dt>Invoice</dt>
                            <dd>{{ $shipment->invoice }}</dd>
                            <dt>Pickup location</dt>
                            <dd>{{ $shipment->pickupLocation }}</dd>
                            <dt>Ship-to</dt>
                            <dd>{{ $shipment->shipTo }}</dd>
                            <dt>Parcel</dt>
                            <dd>{{ $shipment->parcel }}</dd>
                            <dt>Courier</dt>
                            <dd>{{ $shipment->courier ?? 'Not selected' }}</dd>
                            <dt>Provider</dt>
                            <dd>{{ $shipment->provider }}</dd>
                        </dl>
                    </div>
                    <button type="submit" class="btn btn-primary" id="hardware-shipment-submit">{{ $shipment->actionLabel }}</button>
                </form>
            @endif

            @if($shipment->canAssignAwb)
                <form method="POST" action="{{ route('inventory.hardware-fulfilments.awb.store', $fulfilment) }}" id="hardware-awb-form" class="mt-3">
                    @csrf
                    <p class="text-muted small mb-2">Assigns an AWB with the selected Shiprocket courier. The AWB is not invented.</p>
                    <button type="submit" class="btn btn-outline-primary" id="hardware-awb-submit">Assign AWB</button>
                </form>
            @endif
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
                if (!window.confirm('Attach the verified catalog packaging to this fulfilment snapshot? Order parcel will not be changed.')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Attaching…';
            });
        })();

        (function () {
            const form = document.getElementById('hardware-country-form');
            const submit = document.getElementById('hardware-country-submit');
            if (!form || !submit) {
                return;
            }

            form.addEventListener('submit', function (event) {
                if (!window.confirm('Record this exact country on the fulfilment overlay? It will not change billing or infer a country.')) {
                    event.preventDefault();
                    return;
                }
                submit.disabled = true;
                submit.textContent = 'Recording…';
            });
        })();
    </script>
@endpush
