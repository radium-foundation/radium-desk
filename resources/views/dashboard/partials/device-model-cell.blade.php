@props(['serviceCase'])

@php
    use App\Support\DeviceModelFormatter;

    $order = $serviceCase->order;
    $fullModelName = $order?->displayDeviceModelName();
    $shortModelName = DeviceModelFormatter::shortDisplay($fullModelName);
@endphp

<td class="case-meta-cell dashboard-product-cell d-none d-lg-table-cell">
    @if($shortModelName)
        <span data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-title="{{ $fullModelName }}">{{ $shortModelName }}</span>
    @else
        —
    @endif
</td>
