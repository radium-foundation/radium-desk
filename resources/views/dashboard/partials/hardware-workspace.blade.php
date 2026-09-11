@php
    use App\Enums\HardwareWorkspaceScope;

    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $canOperateHardware = (bool) ($hardwareWorkspace['canOperateHardware'] ?? false);
    $search = $hardwareWorkspace['search'] ?? '';
    $isShippedScope = ($hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value) === HardwareWorkspaceScope::Shipped->value;
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
                    <th class="d-none d-lg-table-cell dashboard-hardware-datetime-header">Date</th>
                    <th class="d-none d-md-table-cell">Product</th>
                    <th class="case-serial-cell">Serial</th>
                    <th>Status</th>
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
                        $canMutate = ! $isShippedScope
                            && $row->mutatingAction
                            && (
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
                        <td class="dashboard-hardware-customer-cell">
                            <span class="dashboard-hardware-customer">{{ $row->customer }}</span>
                            @if($row->isB2bCustomer)
                                <sup class="dashboard-hardware-b2b"
                                     title="B2B customer"
                                     aria-label="B2B customer">B</sup>
                            @endif
                        </td>
                        <td class="d-none d-lg-table-cell dashboard-hardware-datetime-cell">
                            <time class="dashboard-timeline-cell dashboard-u-datetime-compact"
                                  datetime="{{ $row->orderDateIst }}"
                                  title="{{ $row->compactTimelineTitle() }}">{{ $row->compactTimelineDisplay() }}</time>
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
                        <td class="dashboard-hardware-status-cell">
                            <span class="dashboard-hardware-status">{{ $row->operatorStatus() }}</span>
                            @if($isShippedScope && $row->awbStatus !== 'Not assigned' && $row->awbStatus !== 'None')
                                <div class="dashboard-hardware-shipment-meta text-muted small">AWB {{ $row->awbStatus }}</div>
                            @endif
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
                @empty
                    <tr>
                        <td colspan="8" class="dashboard-cases-empty">
                            @if($search !== '')
                                No hardware orders match this search.
                            @elseif($isShippedScope)
                                No shipped hardware orders in this view.
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
