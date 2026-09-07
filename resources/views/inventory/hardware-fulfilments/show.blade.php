@extends('layouts.app')

@section('title', 'Allocate hardware serials')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Allocate serials — {{ $fulfilment->source_id }}</h1>
        <p class="text-muted mb-0">
            State {{ $fulfilment->state?->value }}.
            Search available Desk serials at the fulfilment branch and add exactly the required quantity.
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <p class="mb-1"><strong>Fulfilment</strong> {{ $fulfilment->id }}</p>
            <p class="mb-1"><strong>Order</strong> {{ $fulfilment->source_id }}</p>
            <p class="mb-0"><strong>Branch</strong> {{ $fulfilment->fulfilment_branch_id ? ($branches->firstWhere('id', $fulfilment->fulfilment_branch_id)?->code ?? $fulfilment->fulfilment_branch_id) : 'unset — set fulfilment_branch_id before allocation' }}</p>
        </div>
    </div>

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
                    fetch(searchUrl + '?' + new URLSearchParams({
                        commerce_order_item_id: itemId,
                        q: q
                    }), { headers: { 'Accept': 'application/json' } })
                        .then(function (response) { return response.json(); })
                        .then(function (payload) {
                            results.innerHTML = '';
                            (payload.serials || []).forEach(function (row) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'btn btn-sm btn-outline-primary me-2 mb-2';
                                button.textContent = row.serial_number;
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
                            if (!(payload.serials || []).length) {
                                results.textContent = 'No available serials.';
                            }
                        });
                });
            });
        })();
    </script>
@endpush
