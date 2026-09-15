@php
    $incidentId = $incidentId ?? null;
    $operableFulfilmentIds = $operableFulfilmentIds ?? [];
    $canOperateHardware = (bool) ($canOperateHardware ?? false);
    $searchText = strtolower(trim(implode(' ', array_filter([
        $row->sourceId,
        $row->customer,
        $row->product,
        $row->serialStatus,
        collect($row->productDetails())->pluck('label')->implode(' '),
    ]))));
    $canMutate = $row->mutatingAction && (
        ($row->fulfilmentId && isset($operableFulfilmentIds[$row->fulfilmentId]))
        || ($row->nextAction === 'Open Fulfilment' && $canOperateHardware && $row->supportOrderId)
    );
    $actionDialogUrl = $row->fulfilmentId && isset($operableFulfilmentIds[$row->fulfilmentId])
        ? route('inventory.hardware-fulfilments.action-dialog', $row->fulfilmentId)
        : $row->awaitingActionDialogUrl();
@endphp
<tr @class([
        'dashboard-case-row--clickable',
        'dashboard-hardware-row',
    ])
    @if($incidentId) data-incident-id="{{ $incidentId }}" @endif
    data-search-text="{{ $searchText }}"
    data-hardware-order="{{ $row->sourceId }}"
    @if($row->fulfilmentId) data-hardware-fulfilment-id="{{ $row->fulfilmentId }}" @endif
    data-hardware-queue="{{ $row->dashboardQueue()->value }}"
    data-hardware-next-action="{{ $row->nextAction }}">
    <td class="dashboard-select-cell">
        <input type="checkbox"
               class="form-check-input"
               data-hardware-select
               value="{{ $row->sourceId }}"
               aria-label="Select {{ $row->sourceId }}">
    </td>
    <td class="case-order-cell">
        <div class="fw-semibold">{{ $row->sourceId }}</div>
    </td>
    <td>{{ $row->customer }}</td>
    <td class="d-none d-md-table-cell dashboard-hardware-product-cell">
        @include('dashboard.partials.hardware-product-cell', ['row' => $row])
    </td>
    <td class="case-serial-cell">
        @include('inventory.hardware-fulfilments.fragments.serial-summary', [
            'serials' => $row->allocatedSerials(),
            'expected' => $row->expectedSerialQuantity,
            'compact' => $row->serialDisplay(),
            'id' => 'hardware-workspace-serial-'.$row->sourceId,
        ])
    </td>
    <td>
        <span class="dashboard-hardware-status">{{ $row->operatorStatus() }}</span>
        @if($row->source === 'RIN' && $row->operatorStatus() === 'Blocked')
            <div class="text-muted small">RIN mapping required</div>
        @endif
    </td>
    <td class="dashboard-hardware-action-cell">
        @if($canMutate && $actionDialogUrl)
            <button type="button"
                    class="btn btn-sm btn-primary dashboard-btn-compact"
                    data-hardware-action-dialog="{{ $actionDialogUrl }}"
                    data-hardware-fulfilment-link>
                {{ $row->nextAction }}
            </button>
        @else
            <span class="btn btn-sm btn-outline-primary dashboard-btn-compact">{{ $row->nextAction }}</span>
        @endif
    </td>
</tr>
