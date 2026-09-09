@php
    $formId = $formId ?? 'hardware-measured-parcel-form';
    $submitLabel = $submitLabel ?? 'Save packed shipment';
    $ajax = $ajax ?? true;
    $divisor = \App\Services\HardwareFulfilment\HardwareShipmentVolumetricWeight::DIVISOR_CM3_PER_KG;
    $maxDim = \App\Services\HardwareFulfilment\HardwareShipmentVolumetricWeight::MAX_DIMENSION_CM;
    $maxWeight = \App\Services\HardwareFulfilment\HardwareShipmentVolumetricWeight::MAX_WEIGHT_KG;
@endphp
<form method="POST"
      action="{{ route('inventory.hardware-fulfilments.parcel-measured.store', $fulfilment) }}"
      @if($ajax) data-hardware-action-form @endif
      data-hardware-measured-parcel
      data-volumetric-divisor="{{ $divisor }}"
      data-max-dimension="{{ $maxDim }}"
      data-max-weight="{{ $maxWeight }}"
      id="{{ $formId }}"
      class="{{ $formClass ?? '' }}">
    @csrf
    <x-c360.section-card title="Package Dimensions — Complete Packed Shipment" class="mb-2">
        <p class="small text-muted mb-3">
            Enter the dimensions and weight of the complete packed shipment, including the outer carton and packing material. Do not enter individual product dimensions.
        </p>
        <div class="row g-2 mb-2">
            <div class="col-4">
                <label class="form-label small mb-1" for="{{ $formId }}-length">Length (cm)</label>
                <input class="form-control form-control-sm"
                       type="number"
                       inputmode="decimal"
                       step="0.01"
                       min="0.51"
                       max="{{ $maxDim }}"
                       name="length"
                       id="{{ $formId }}-length"
                       data-hardware-measured-length
                       required>
            </div>
            <div class="col-4">
                <label class="form-label small mb-1" for="{{ $formId }}-breadth">Breadth (cm)</label>
                <input class="form-control form-control-sm"
                       type="number"
                       inputmode="decimal"
                       step="0.01"
                       min="0.51"
                       max="{{ $maxDim }}"
                       name="breadth"
                       id="{{ $formId }}-breadth"
                       data-hardware-measured-breadth
                       required>
            </div>
            <div class="col-4">
                <label class="form-label small mb-1" for="{{ $formId }}-height">Height (cm)</label>
                <input class="form-control form-control-sm"
                       type="number"
                       inputmode="decimal"
                       step="0.01"
                       min="0.51"
                       max="{{ $maxDim }}"
                       name="height"
                       id="{{ $formId }}-height"
                       data-hardware-measured-height
                       required>
            </div>
        </div>
        <div class="mb-2">
            <label class="form-label small mb-1" for="{{ $formId }}-weight">Actual Packed Weight (kg)</label>
            <input class="form-control form-control-sm"
                   type="number"
                   inputmode="decimal"
                   step="0.001"
                   min="0.001"
                   max="{{ $maxWeight }}"
                   name="weight"
                   id="{{ $formId }}-weight"
                   data-hardware-measured-weight
                   required>
        </div>
        <dl class="row small mb-2">
            <dt class="col-6">Actual Weight</dt>
            <dd class="col-6 mb-1" data-hardware-measured-actual-display>—</dd>
            <dt class="col-6">Volumetric Weight</dt>
            <dd class="col-6 mb-1" data-hardware-measured-volumetric-display>—</dd>
        </dl>
        <p class="small text-muted mb-2">
            Volumetric weight is L × B × H ÷ {{ $divisor }} (cm → kg). Desk sends the actual packed weight to Shiprocket. Chargeable weight is calculated by the provider.
        </p>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" name="save_for_future" id="{{ $formId }}-future">
            <label class="form-check-label small" for="{{ $formId }}-future">
                Save Package Dimension for future order
            </label>
        </div>
        <p class="small text-muted mb-0">
            This does not change the verified unit packaging catalog. Quantity-specific cartons stay on this fulfilment only.
        </p>
        @unless($ajax)
            <button type="submit" class="btn btn-outline-primary mt-3" data-hardware-measured-submit disabled>
                {{ $submitLabel }}
            </button>
        @endunless
    </x-c360.section-card>
    @if($ajax)
        <x-c360.modal-footer>
            <button type="button" class="btn c360-dialog-btn-ghost" data-bs-dismiss="modal">Cancel</button>
            <button type="submit"
                    class="btn c360-dialog-btn-primary"
                    data-hardware-action-submit
                    data-hardware-measured-submit
                    disabled>
                {{ $submitLabel }}
            </button>
        </x-c360.modal-footer>
    @endif
</form>
