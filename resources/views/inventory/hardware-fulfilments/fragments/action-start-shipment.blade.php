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

@if(! empty($prepError))
    <div class="alert alert-warning py-2 px-3 small mb-2" role="alert">{{ $prepError }}</div>
@endif

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
                before shipping.
            </p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Attach verified packaging
            </button>
        </x-c360.modal-footer>
    </form>
@elseif($ready->canAttachMeasuredParcel)
    @include('inventory.hardware-fulfilments.fragments.action-measure-parcel')
@elseif($row->nextAction === 'Confirm Recommended Courier' && $ready->canConfirmRecommendedCourier)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.confirm-recommended-courier.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-courier-confirm-form">
        @csrf
        <x-c360.section-card title="Recommended courier" class="mb-2">
            @if($ready->recommendationNote !== '')
                <p class="small mb-2 {{ $ready->recommendationReturned ? 'text-success' : 'text-muted' }}">{{ $ready->recommendationNote }}</p>
            @endif
            <p class="small mb-0">
                <strong>Recommended:</strong>
                {{ $ready->recommendedCourierLabel ?? $ready->courier ?? 'Not available yet' }}
            </p>
            <p class="small text-muted mb-0 mt-2">Confirm this courier, then ship and generate the label in one step.</p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="button"
                    class="btn c360-dialog-btn-ghost"
                    data-hardware-courier-change-toggle
                    aria-expanded="false"
                    aria-controls="hardware-action-courier-change-panel">
                Change Courier
            </button>
            <button type="submit"
                    class="btn c360-dialog-btn-primary"
                    data-hardware-action-submit
                    aria-label="Confirm recommended courier">
                Confirm Courier &amp; Continue
            </button>
        </x-c360.modal-footer>
    </form>
    <div id="hardware-action-courier-change-panel" class="d-none mt-2" hidden>
        @include('inventory.hardware-fulfilments.fragments.action-courier-select', [
            'formId' => 'hardware-action-courier-change-form',
            'submitLabel' => 'Select Courier',
        ])
    </div>
@elseif($row->nextAction === 'Ship & Generate Label' && $ready->canShipAndGenerateLabel)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.ship-and-label.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-ship-and-label-form">
        @csrf
        <x-c360.section-card title="Ship &amp; generate label" class="mb-2">
            <dl class="row small mb-0">
                <dt class="col-4">Courier</dt>
                <dd class="col-8">{{ $ready->courier ?? $ready->recommendedCourierLabel ?? 'Not selected' }}</dd>
                <dt class="col-4">Payment</dt>
                <dd class="col-8">{{ $ready->collectionModeLabel }}</dd>
            </dl>
            <p class="small text-muted mb-0 mt-2">Creates the Shiprocket shipment, assigns the AWB, and generates the label.</p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            @if($ready->canSelectCourier)
                <button type="button"
                        class="btn c360-dialog-btn-ghost"
                        data-hardware-courier-change-toggle
                        aria-expanded="false"
                        aria-controls="hardware-action-courier-change-panel">
                    Change Courier
                </button>
            @endif
            <button type="submit"
                    class="btn c360-dialog-btn-primary"
                    data-hardware-action-submit
                    aria-label="Ship and generate label">
                Ship &amp; Generate Label
            </button>
        </x-c360.modal-footer>
    </form>
    @if($ready->canSelectCourier)
        <div id="hardware-action-courier-change-panel" class="d-none mt-2" hidden>
            @include('inventory.hardware-fulfilments.fragments.action-courier-select', [
                'formId' => 'hardware-action-courier-change-form',
                'submitLabel' => 'Select Courier',
            ])
        </div>
    @endif
@elseif($row->nextAction === 'Get Courier Options' && $ready->canFetchCourierOptions)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.courier-options.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-courier-options-form">
        @csrf
        <x-c360.section-card title="Courier options" class="mb-2">
            <p class="small text-muted mb-0">Fetches current Shiprocket serviceability for this prepared shipment.</p>
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Get Courier Options
            </button>
        </x-c360.modal-footer>
    </form>
@elseif($row->nextAction === 'Select Courier' && $ready->canSelectCourier)
    @include('inventory.hardware-fulfilments.fragments.action-courier-select', [
        'formId' => 'hardware-action-courier-select-form',
        'submitLabel' => 'Select Courier',
    ])
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
