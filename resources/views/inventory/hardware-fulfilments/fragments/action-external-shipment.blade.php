@if($ready->canRecordExternalShipment)
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.external-shipment.store', $fulfilment) }}"
          data-hardware-action-form
          enctype="multipart/form-data"
          id="hardware-action-external-shipment-form">
        @csrf
        <x-c360.section-card title="Manual / External Courier" class="mb-2">
            <p class="small text-muted mb-3">Record shipment details for a courier outside the Shiprocket workflow.</p>
            @include('inventory.hardware-fulfilments.fragments.external-shipment-form-fields', [
                'formIdPrefix' => 'hardware-external',
            ])
        </x-c360.section-card>
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Record Shipment
            </button>
        </x-c360.modal-footer>
    </form>
@elseif($ready->canExternalDispatch)
    <x-c360.section-card title="External shipment" class="mb-2">
        <dl class="row small mb-0">
            <dt class="col-4">Method</dt>
            <dd class="col-8">{{ $ready->shippingMethodLabel }}</dd>
            <dt class="col-4">Courier</dt>
            <dd class="col-8">{{ $ready->courier ?? '—' }}</dd>
            <dt class="col-4">AWB</dt>
            <dd class="col-8">{{ $ready->awb ?? '—' }}</dd>
            @if($ready->trackingUrl)
                <dt class="col-4">Tracking URL</dt>
                <dd class="col-8"><a href="{{ $ready->trackingUrl }}" target="_blank" rel="noopener noreferrer">{{ $ready->trackingUrl }}</a></dd>
            @endif
        </dl>
    </x-c360.section-card>
    <form method="POST"
          action="{{ route('inventory.hardware-fulfilments.external-dispatch.store', $fulfilment) }}"
          data-hardware-action-form
          id="hardware-action-external-dispatch-form">
        @csrf
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn c360-dialog-btn-primary" data-hardware-action-submit>
                Mark Dispatched
            </button>
        </x-c360.modal-footer>
    </form>
@else
    <x-c360.section-card title="External shipment">
        @if($ready->blockers !== [])
            <ul class="small text-danger mb-0">
                @foreach($ready->blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>
        @else
            <p class="small mb-0">External shipment is not ready for the next step.</p>
        @endif
    </x-c360.section-card>
    <x-c360.modal-footer>
        <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
        @if($showUrl ?? null)
            <a class="btn c360-dialog-btn-primary" href="{{ $showUrl }}">Open Fulfilment</a>
        @endif
    </x-c360.modal-footer>
@endif
