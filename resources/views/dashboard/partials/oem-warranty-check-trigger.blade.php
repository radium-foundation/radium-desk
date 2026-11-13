@props(['vendor' => null, 'incidentId' => null])

@php
    use App\Support\Oem\OemWarrantyCheckVendor;

    $vendorEnum = $vendor instanceof OemWarrantyCheckVendor ? $vendor : null;
@endphp

@if($vendorEnum)
    <button type="button"
            class="serial-validation-indicator oem-warranty-check-trigger"
            data-oem-warranty-check
            data-oem-vendor="{{ $vendorEnum->value }}"
            @if($incidentId)
                data-incident-id="{{ $incidentId }}"
            @endif
            data-bs-toggle="tooltip"
            data-bs-placement="top"
            data-bs-title="Check OEM warranty / renewal"
            aria-label="Check OEM warranty / renewal on {{ $vendorEnum === OemWarrantyCheckVendor::Mantra ? 'Mantra' : 'Morpho' }} site">
        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
    </button>
@endif
