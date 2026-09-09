@php
    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $canOperateHardware = (bool) ($hardwareWorkspace['canOperateHardware'] ?? false);
    $search = $hardwareWorkspace['search'] ?? '';
@endphp

<div id="dashboard-hardware-workspace" data-hardware-workspace>
    <div class="dashboard-hardware-selection d-none" data-hardware-selection-bar hidden>
        <span class="dashboard-hardware-selection__count" data-hardware-selection-count>0 selected</span>
        <button type="button"
                class="btn btn-sm btn-outline-primary dashboard-btn-compact"
                data-hardware-open-selected
                disabled>
            Open selected
        </button>
        <button type="button"
                class="btn btn-sm btn-outline-secondary dashboard-btn-compact"
                data-hardware-clear-selected>
            Clear
        </button>
    </div>

    <div class="dashboard-cases-table-wrap @if($rows->isEmpty()) dashboard-cases-table-wrap--empty @endif">
        <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table dashboard-hardware-table">
            <thead class="table-light">
                <tr>
                    <th class="dashboard-select-cell">
                        <input type="checkbox"
                               class="form-check-input"
                               data-hardware-select-all
                               aria-label="Select visible hardware orders">
                    </th>
                    <th>Order</th>
                    <th>Customer</th>
                    <th class="d-none d-md-table-cell">Product</th>
                    <th class="case-serial-cell">Serial</th>
                    <th>Status</th>
                    <th class="dashboard-date-cell d-none d-sm-table-cell">Date/Time</th>
                    <th class="dashboard-hardware-action-cell">Next Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $row)
                    @php
                        $incidentId = $row->supportOrderId !== null
                            ? ($incidentIds[$row->supportOrderId] ?? null)
                            : null;
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
                        $orderDateFull = $row->orderDateDisplay();
                        $orderDateShort = $row->orderDateDisplayCompact();
                    @endphp
                    <tr @class([
                            'dashboard-case-row--clickable',
                            'dashboard-hardware-row',
                        ])
                        @if($incidentId) data-incident-id="{{ $incidentId }}" @endif
                        data-search-text="{{ $searchText }}"
                        data-hardware-order="{{ $row->sourceId }}"
                        @if($row->fulfilmentId) data-hardware-fulfilment-id="{{ $row->fulfilmentId }}" @endif
                        data-hardware-next-action="{{ $row->nextAction }}">
                        <td class="dashboard-select-cell">
                            <input type="checkbox"
                                   class="form-check-input"
                                   data-hardware-select
                                   value="{{ $row->sourceId }}"
                                   aria-label="Select {{ $row->sourceId }}">
                        </td>
                        <td class="case-order-cell">
                            <div class="dashboard-hardware-order">{{ $row->sourceId }}</div>
                        </td>
                        <td class="case-meta-cell dashboard-hardware-customer-cell">
                            <span class="dashboard-hardware-customer" title="{{ $row->customer }}">{{ $row->customer }}</span>
                        </td>
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
                        <td class="status-cell">
                            <span class="dashboard-hardware-status" title="{{ $row->operatorStatus() }}">{{ $row->operatorStatus() }}</span>
                            @if($row->source === 'RIN' && $row->operatorStatus() === 'Blocked')
                                <span class="dashboard-hardware-status-note" title="RIN mapping required">RIN mapping required</span>
                            @endif
                        </td>
                        <td class="dashboard-date-cell d-none d-sm-table-cell text-nowrap">
                            @if($orderDateFull === '—')
                                —
                            @else
                                <span class="dashboard-u-datetime-compact dashboard-hardware-datetime">
                                    <span class="dashboard-hardware-datetime__full">{{ $orderDateFull }}</span>
                                    <span class="dashboard-hardware-datetime__short">{{ $orderDateShort }}</span>
                                </span>
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
                @empty
                    <tr>
                        <td colspan="8" class="dashboard-cases-empty">
                            @if($search !== '')
                                No hardware orders match this search.
                            @else
                                No hardware orders in this queue.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
