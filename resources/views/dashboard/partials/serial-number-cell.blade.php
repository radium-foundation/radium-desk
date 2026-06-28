@props(['serviceCase'])

@php
    $order = $serviceCase->order;
    $isLocked = $order?->isSerialLocked() ?? false;
    $canAssign = $order && auth()->user()?->can('assignSerial', $order);
@endphp

<td class="serial-number-cell case-serial-cell case-meta-cell">
    @if($isLocked && $order?->serial_number)
        <a href="{{ route('orders.show', $order) }}" class="text-decoration-none">{{ $order->serial_number }}</a>
    @elseif($canAssign)
        <button type="button"
                class="serial-cell-trigger dashboard-u-transaction-add dashboard-u-transition dashboard-u-focus-ring"
                data-serial-modal-trigger="true"
                data-order-id="{{ $order->id }}"
                data-incident-id="{{ $serviceCase->id }}"
                data-store-url="{{ route('orders.serial.store', $order) }}"
                aria-label="Enter serial number"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                data-bs-title="Enter serial number">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
    @else
        —
    @endif
</td>
