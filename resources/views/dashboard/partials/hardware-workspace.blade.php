@php
    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $search = $hardwareWorkspace['search'] ?? '';
@endphp

<div id="dashboard-hardware-workspace" data-hardware-workspace>
    <div class="dashboard-cases-table-wrap @if($rows->isEmpty()) dashboard-cases-table-wrap--empty @endif">
        <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table dashboard-hardware-table">
            <thead class="table-light">
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th class="d-none d-md-table-cell">Product</th>
                    <th class="case-serial-cell">Serial</th>
                    <th>Status</th>
                    <th class="dashboard-hardware-action-cell">Next action</th>
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
                        ]))));
                    @endphp
                    <tr @class([
                            'dashboard-case-row--clickable',
                            'dashboard-hardware-row',
                        ])
                        @if($incidentId) data-incident-id="{{ $incidentId }}" @endif
                        data-search-text="{{ $searchText }}"
                        data-hardware-order="{{ $row->sourceId }}">
                        <td class="case-order-cell">
                            <div class="fw-semibold">{{ $row->sourceId }}</div>
                        </td>
                        <td>{{ $row->customer }}</td>
                        <td class="d-none d-md-table-cell">{{ $row->productDisplay() }}</td>
                        <td class="case-serial-cell">{{ $row->serialDisplay() }}</td>
                        <td>
                            <span class="dashboard-hardware-status">{{ $row->operatorStatus() }}</span>
                            @if($row->source === 'RIN' && $row->operatorStatus() === 'Blocked')
                                <div class="text-muted small">RIN mapping required</div>
                            @endif
                        </td>
                        <td class="dashboard-hardware-action-cell">
                            @if($row->mutatingAction && $row->fulfilmentId && isset($operableFulfilmentIds[$row->fulfilmentId]))
                                <a class="btn btn-sm btn-primary dashboard-btn-compact"
                                   href="{{ $row->primaryUrl() }}"
                                   data-hardware-fulfilment-link>{{ $row->nextAction }}</a>
                            @else
                                <span class="btn btn-sm btn-outline-primary dashboard-btn-compact">{{ $row->nextAction }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="dashboard-cases-empty">
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
