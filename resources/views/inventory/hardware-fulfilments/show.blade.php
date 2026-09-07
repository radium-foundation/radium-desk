@extends('layouts.app')

@section('title', 'Allocate hardware serials')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Allocate serials — {{ $fulfilment->source_id }}</h1>
        <p class="text-muted mb-0">
            State {{ $fulfilment->state?->value }}.
            Search available Desk serials by their physical stock branch, then allocate.
            Fulfilment branch is derived from the selected serials, never from customer state.
            Free-text paste is not used.
        </p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'hardware-fulfilments'])

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">{{ $errors->first() }}</div>
    @endif

    @php
        $lockedBranch = $fulfilment->fulfilment_branch_id
            ? ($branches->firstWhere('id', $fulfilment->fulfilment_branch_id)?->code ?? (string) $fulfilment->fulfilment_branch_id)
            : null;
    @endphp

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <p class="mb-1"><strong>Fulfilment</strong> {{ $fulfilment->id }}</p>
            <p class="mb-1"><strong>Order</strong> {{ $fulfilment->source_id }}</p>
            <p class="mb-0">
                <strong>Stock branch</strong>
                {{ $lockedBranch ?? 'unset — select physical serials; the allocation transaction will write the branch from inventory_serials.branch_id' }}
            </p>
        </div>
    </div>

    @if($fulfilment->shipment)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Shipment</h2>
                <p class="mb-1"><strong>Number</strong> {{ $fulfilment->shipment->shipment_no }}</p>
                <p class="mb-1"><strong>Provider shipment</strong> {{ $fulfilment->shipment->external_shipment_id ?? 'pending' }}</p>
                <p class="mb-0"><strong>AWB</strong> {{ $fulfilment->shipment->awb ?? 'not assigned' }}</p>
            </div>
        </div>
    @endif

    @if($fulfilment->state?->value === 'invoice_issued')
        <form method="POST" action="{{ route('inventory.hardware-fulfilments.shipment.store', $fulfilment) }}" class="mb-4">
            @csrf
            <button class="btn btn-primary">Create shipment</button>
        </form>
    @endif

    @if($fulfilment->state?->value === 'shipment_created')
        <form method="POST" action="{{ route('inventory.hardware-fulfilments.awb.store', $fulfilment) }}" class="mb-4">
            @csrf
            <button class="btn btn-primary">Assign AWB</button>
        </form>
    @endif

    @if($allocated->isNotEmpty())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h6">Allocated serials</h2>
                <ol class="mb-0">
                    @foreach($allocated as $serial)
                        <li>{{ $serial->serial_number }}</li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endif

    @if($fulfilment->state?->value === 'ready_for_fulfilment')
        <form method="POST" action="{{ route('inventory.hardware-fulfilments.serials.store', $fulfilment) }}" id="hardware-serial-allocate-form">
            @csrf
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <label class="form-label" for="stock-branch-filter">Physical stock branch filter</label>
                    <select
                        id="stock-branch-filter"
                        name="claimed_branch"
                        class="form-select"
                        @disabled($lockedBranch !== null)
                    >
                        @if($lockedBranch === null)
                            <option value="">Select Delhi or Mumbai stock</option>
                        @endif
                        @foreach($stockBranches as $stockBranch)
                            <option
                                value="{{ $stockBranch->code }}"
                                @selected($lockedBranch === $stockBranch->code)
                            >
                                @if($stockBranch->code === 'DELHI-RETAIL')
                                    Delhi / DELHI-RETAIL
                                @elseif($stockBranch->code === 'MUMBAI')
                                    Mumbai / MUMBAI
                                @else
                                    {{ $stockBranch->code }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                    @if($lockedBranch !== null)
                        <input type="hidden" name="claimed_branch" value="{{ $lockedBranch }}">
                    @endif
                    <p class="text-muted small mb-0 mt-2">
                        This filter only searches stock. Allocation reads each serial's actual inventory branch and rejects mixed Delhi + Mumbai selections.
                    </p>
                </div>
            </div>
            @foreach($requirements as $line)
                <div class="card border-0 shadow-sm mb-4" data-item-id="{{ $line['commerce_order_item_id'] }}" data-qty="{{ $line['qty'] }}">
                    <div class="card-body">
                        <h2 class="h6">{{ $line['description'] }}</h2>
                        <p class="text-muted small mb-2">
                            Qty {{ $line['qty'] }}
                            · model_id {{ $line['model_id'] ?? 'unset' }}
                            · channel SKU {{ $line['sku'] ?? 'unset' }}
                            · catalog {{ $line['catalog_sku'] ?? 'unset' }}
                            @if($line['rdserviceid']) · bundled RD #{{ $line['rdserviceid'] }} @endif
                            · Desk {{ $line['inventory_sku'] ?? 'map missing' }}
                            · available {{ $line['available_qty'] }}
                            · Delhi {{ $line['available_by_branch']['DELHI-RETAIL'] ?? 0 }}
                            · Mumbai {{ $line['available_by_branch']['MUMBAI'] ?? 0 }}
                        </p>
                        @if(! $line['map_ready'])
                            <p class="text-danger small">Owner SKU map is missing for this model_id. Allocation is blocked.</p>
                        @endif
                        <div class="input-group mb-2">
                            <input type="search" class="form-control js-serial-query" placeholder="Search available serials" @disabled(! $line['map_ready'])>
                            <button type="button" class="btn btn-outline-secondary js-serial-search" @disabled(! $line['map_ready'])>Search</button>
                        </div>
                        <div class="js-serial-results mb-2"></div>
                        <ul class="js-serial-selected list-unstyled mb-0"></ul>
                    </div>
                </div>
            @endforeach
            <button class="btn btn-primary" @disabled(collect($requirements)->contains(fn ($line) => ! $line['map_ready']))>Allocate selected serials</button>
        </form>
    @endif
@endsection

@push('scripts')
    <script>
        (function () {
            const searchUrl = @json(route('inventory.hardware-fulfilments.serials.search', $fulfilment));
            const branchFilter = document.getElementById('stock-branch-filter');
            document.querySelectorAll('[data-item-id]').forEach(function (card) {
                const itemId = card.getAttribute('data-item-id');
                const qty = Number(card.getAttribute('data-qty') || '0');
                const results = card.querySelector('.js-serial-results');
                const selected = card.querySelector('.js-serial-selected');
                const chosen = [];

                function renderSelected() {
                    selected.innerHTML = chosen.map(function (serial) {
                        return '<li>' + serial + '<input type="hidden" name="serials[' + itemId + '][]" value="' + serial + '"></li>';
                    }).join('');
                }

                card.querySelector('.js-serial-search')?.addEventListener('click', function () {
                    const q = card.querySelector('.js-serial-query')?.value || '';
                    const branch = branchFilter?.value || '';
                    fetch(searchUrl + '?' + new URLSearchParams({
                        commerce_order_item_id: itemId,
                        q: q,
                        branch: branch
                    }), { headers: { 'Accept': 'application/json' } })
                        .then(function (response) {
                            return response.json().then(function (payload) {
                                return { ok: response.ok, payload: payload };
                            });
                        })
                        .then(function (result) {
                            results.innerHTML = '';
                            if (!result.ok) {
                                const errors = result.payload.errors || {};
                                const first = (errors.branch || errors.serials || [result.payload.message || 'Search failed.'])[0];
                                results.textContent = first;
                                return;
                            }
                            (result.payload.serials || []).forEach(function (row) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'btn btn-sm btn-outline-primary me-2 mb-2';
                                button.textContent = row.serial_number + (row.branch_code ? ' · ' + row.branch_code : '');
                                button.addEventListener('click', function () {
                                    if (chosen.includes(row.serial_number)) {
                                        return;
                                    }
                                    if (chosen.length >= qty) {
                                        return;
                                    }
                                    chosen.push(row.serial_number);
                                    renderSelected();
                                });
                                results.appendChild(button);
                            });
                            if (!(result.payload.serials || []).length) {
                                results.textContent = 'No available serials.';
                            }
                        });
                });
            });
        })();
    </script>
@endpush
