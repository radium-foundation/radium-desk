@php
    use App\Enums\HardwareDashboardQueue;

    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $canOperateHardware = (bool) ($hardwareWorkspace['canOperateHardware'] ?? false);
    $search = $hardwareWorkspace['search'] ?? '';
    $isShippedScope = ($hardwareWorkspace['queue'] ?? '') === HardwareDashboardQueue::Completed->value;
@endphp

<div id="dashboard-hardware-workspace" data-hardware-workspace data-hardware-queue="{{ $hardwareWorkspace['queue'] ?? '' }}">
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
            <tbody id="dashboard-hardware-body">
                @forelse($rows as $row)
                    @php
                        $incidentId = $row->supportOrderId !== null
                            ? ($incidentIds[$row->supportOrderId] ?? null)
                            : null;
                    @endphp
                    @include('dashboard.partials.hardware-workspace-row', [
                        'row' => $row,
                        'incidentId' => $incidentId !== null ? (int) $incidentId : null,
                        'operableFulfilmentIds' => $operableFulfilmentIds,
                        'canOperateHardware' => $canOperateHardware,
                        'isShippedScope' => $isShippedScope,
                    ])
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
