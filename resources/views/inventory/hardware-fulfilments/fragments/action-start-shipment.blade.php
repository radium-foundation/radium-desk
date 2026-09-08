<x-c360.section-card title="Shipment summary" class="mb-2">
    <dl class="row small mb-0">
        <dt class="col-4">Customer</dt>
        <dd class="col-8">{{ $ready->customer ?? $row->customer }}</dd>
        <dt class="col-4">Destination</dt>
        <dd class="col-8">{{ $ready->shipTo ?? 'Incomplete' }}</dd>
        <dt class="col-4">Product</dt>
        <dd class="col-8">{{ $row->productDisplay() }}</dd>
        <dt class="col-4">Quantity</dt>
        <dd class="col-8">{{ $row->quantity !== '—' && $row->quantity !== '' ? $row->quantity : '—' }}</dd>
        <dt class="col-4">Pickup</dt>
        <dd class="col-8">
            @if($ready->pickupBranch || $ready->pickupLocation)
                {{ $ready->pickupBranch ?? '' }}{{ $ready->pickupLocation ? ' · '.$ready->pickupLocation : '' }}
            @else
                Not derived yet
            @endif
        </dd>
        <dt class="col-4">Parcel</dt>
        <dd class="col-8">{{ $ready->parcel ?: 'Not attached' }}</dd>
        <dt class="col-4">Payment</dt>
        <dd class="col-8">{{ $ready->collectionModeLabel }}</dd>
    </dl>
</x-c360.section-card>

@if($ready->canAttachSnapshot)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.parcel-snapshot.store', $fulfilment) }}"
          data-hardware-action-form
          class="mb-2"
          id="hardware-action-parcel-form">
        @csrf
        <x-c360.section-card title="Parcel" class="mb-2">
            <p class="small text-muted mb-0">
                Attach the verified packaging for this product
                @if($ready->catalogPackaging)
                    ({{ $ready->catalogPackaging }})
                @endif
                before fetching courier options.
            </p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Attach verified packaging
            </button>
        </x-c360.modal-footer>
    </form>
@elseif($row->nextAction === 'Get Courier Options' && $ready->canFetchCourierOptions)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.courier-options.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-courier-options-form">
        @csrf
        <x-c360.section-card title="Courier options" class="mb-2">
            <p class="small text-muted mb-0">Fetches current Shiprocket serviceability for this prepared shipment. No courier is selected automatically.</p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Get Courier Options
            </button>
        </x-c360.modal-footer>
    </form>
@elseif($row->nextAction === 'Select Courier' && $ready->canSelectCourier)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.courier.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-courier-select-form">
        @csrf
        <x-c360.section-card title="Returned services" class="mb-2">
            @if($ready->recommendationNote !== '')
                <p class="small mb-2 {{ $ready->recommendationReturned ? 'text-success' : 'text-muted' }}">{{ $ready->recommendationNote }}</p>
            @endif
            @foreach($ready->courierOptions as $option)
                @php
                    $optionId = (string) ($option['courier_id'] ?? '');
                    $optionLabel = $option['courier_name'] ?? $optionId;
                    if (! empty($option['courier_type'])) {
                        $optionLabel .= ' · '.$option['courier_type'];
                    }
                    if (! empty($option['mode'])) {
                        $optionLabel .= ' · '.$option['mode'];
                    }
                    if (($option['rate'] ?? null) !== null) {
                        $optionLabel .= ' · '.$option['rate'];
                    }
                    $recommended = ! empty($option['provider_recommended']);
                @endphp
                <div class="form-check mb-2 @if($recommended) hardware-action-courier--recommended @endif">
                    <input class="form-check-input"
                           type="radio"
                           name="courier_id"
                           id="hardware-action-courier-{{ $optionId }}"
                           value="{{ $optionId }}"
                           required
                           @checked($ready->selectedCourierId === $optionId)>
                    <label class="form-check-label small" for="hardware-action-courier-{{ $optionId }}">
                        {{ $optionLabel }}
                        @if($recommended)
                            <span class="text-success"> · Recommended</span>
                        @endif
                    </label>
                </div>
            @endforeach
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Select Courier
            </button>
        </x-c360.modal-footer>
    </form>
@elseif(in_array($row->nextAction, ['Create Shipment', 'Reconcile Shipment'], true) && $ready->canCreate)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.shipment.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-create-shipment-form">
        @csrf
        <x-c360.section-card title="Create shipment" class="mb-2">
            <dl class="row small mb-0">
                <dt class="col-4">Courier</dt>
                <dd class="col-8">{{ $ready->courier ?? 'Not selected' }}</dd>
                <dt class="col-4">Payment</dt>
                <dd class="col-8">{{ $ready->collectionModeLabel }}</dd>
            </dl>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Create Shipment
            </button>
        </x-c360.modal-footer>
    </form>
@else
    <x-c360.section-card title="Shipment">
        @if($ready->blockers !== [])
            <ul class="small text-danger mb-0">
                @foreach($ready->blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        @else
            <p class="small mb-0">Open Fulfilment to continue shipment prep.</p>
        @endif
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        @if($showUrl ?? null)
            <a class="btn c360-dialog-btn-primary" href="{{ $showUrl }}">Open Fulfilment</a>
        @endif
    </x-c360.modal-footer>
@endif
