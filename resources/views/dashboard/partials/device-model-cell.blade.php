@props(['serviceCase'])

@php
    use App\Support\DeviceModelFormatter;

    $order = $serviceCase->order;
    $fullModelName = $order?->displayDeviceModelName();
    $shortModelName = DeviceModelFormatter::shortDisplay($fullModelName);
    $canAssign = $order && auth()->user()?->can('assignDeviceModel', $order);
@endphp

<td class="case-meta-cell dashboard-product-cell d-none d-lg-table-cell"
    @if($canAssign)
        data-device-model-cell="true"
        data-order-id="{{ $order->id }}"
        data-incident-id="{{ $serviceCase->id }}"
        data-store-url="{{ route('orders.device-model.store', $order) }}"
    @endif>
    @if($shortModelName)
        <span data-bs-toggle="tooltip"
              data-bs-placement="top"
              data-bs-title="{{ $fullModelName }}">{{ $shortModelName }}</span>
    @elseif($canAssign)
        <button type="button"
                class="device-model-cell-trigger transaction-cell-trigger dashboard-u-transaction-add dashboard-u-transition dashboard-u-focus-ring"
                aria-label="Assign model"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                data-bs-title="Assign model">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
    @else
        —
    @endif
</td>
