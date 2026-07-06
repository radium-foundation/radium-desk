@props(['serviceCase'])

@php
    $order = $serviceCase->order;
    $isLocked = $order?->isSerialLocked() ?? false;
    $canAssign = $order && auth()->user()?->can('assignSerial', $order);
@endphp

<td class="serial-number-cell case-serial-cell case-meta-cell"
    @if($canAssign && ! $isLocked)
        data-inline-serial="true"
        data-order-id="{{ $order->id }}"
        data-incident-id="{{ $serviceCase->id }}"
        data-store-url="{{ route('orders.serial.store', $order) }}"
    @endif>
    @if($isLocked && $order?->serial_number)
        <div class="d-flex align-items-center gap-1">
            <span class="serial-display-value dashboard-u-serial-value"
                  data-bs-toggle="tooltip"
                  data-bs-placement="top"
                  data-bs-title="{{ $order->serial_number }}">{{ $order->serial_number }}</span>
            @include('orders.partials.serial-validation-badge', ['order' => $order, 'compact' => true])
        </div>
    @elseif($canAssign)
        <button type="button"
                class="serial-cell-trigger transaction-cell-trigger dashboard-u-transaction-add dashboard-u-transition dashboard-u-focus-ring"
                aria-label="Enter serial number"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                data-bs-title="Enter serial number">
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
        </button>
        <div class="transaction-inline-editor d-none">
            <div class="input-group input-group-sm">
                <input type="text"
                       class="form-control transaction-inline-input serial-inline-input"
                       placeholder="Serial Number"
                       maxlength="100"
                       aria-label="Serial Number">
                <button type="button" class="btn btn-outline-success serial-inline-save" aria-label="Save">
                    <i class="bi bi-check-lg"></i>
                </button>
            </div>
            <div class="invalid-feedback small transaction-inline-error serial-inline-error"></div>
        </div>
    @else
        —
    @endif
</td>
