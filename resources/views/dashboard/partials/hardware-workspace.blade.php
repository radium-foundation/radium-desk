@php
    use App\Enums\HardwareWorkspaceScope;

    $deferRows = (bool) ($hardwareWorkspace['defer_rows'] ?? false);
    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $canOperateHardware = (bool) ($hardwareWorkspace['canOperateHardware'] ?? false);
    $search = $hardwareWorkspace['search'] ?? '';
    $isShippedScope = ($hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value) === HardwareWorkspaceScope::Shipped->value;
    $skeletonCount = (int) ($hardwareWorkspace['per_page'] ?? 40);
@endphp

<div id="dashboard-hardware-workspace"
     data-hardware-workspace
     data-hardware-defer-rows="{{ $deferRows ? '1' : '0' }}"
     data-hardware-rows-url="{{ route('dashboard.hardware.rows') }}"
     data-hardware-shiprocket-url="{{ route('dashboard.hardware.shiprocket-balance') }}"
     data-hardware-scope="{{ $hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value }}"
     data-hardware-filter="{{ $hardwareWorkspace['filter'] ?? 'needs_action' }}"
     data-hardware-page="{{ $hardwareWorkspace['page'] ?? 1 }}"
     data-hardware-per-page="{{ $hardwareWorkspace['per_page'] ?? 40 }}"
     data-hardware-inspected-count="{{ $hardwareWorkspace['inspected_fulfilment_count'] ?? 0 }}"
     @if($search !== '') data-hardware-search="{{ $search }}" @endif>
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

    <div class="visually-hidden"
         aria-live="polite"
         aria-atomic="true"
         data-hardware-rows-status></div>

    <div class="dashboard-hardware-rows-error alert alert-warning d-none"
         role="alert"
         data-hardware-rows-error
         hidden>
        <span data-hardware-rows-error-text>Hardware queue rows could not be loaded.</span>
        <button type="button"
                class="btn btn-sm btn-outline-secondary ms-2"
                data-hardware-rows-retry>
            Retry
        </button>
    </div>

    <div class="dashboard-cases-table-wrap @if(!$deferRows && $rows->isEmpty()) dashboard-cases-table-wrap--empty @endif"
         @class(['dashboard-hardware-table-wrap--loading' => $deferRows])>
        <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table dashboard-hardware-table">
            <thead class="table-light">
                <tr>
                    <th class="dashboard-select-cell">
                        <input type="checkbox"
                               class="form-check-input"
                               data-hardware-select-all
                               @if($deferRows) disabled @endif
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
                @if($deferRows)
                    @for($i = 0; $i < $skeletonCount; $i++)
                        <tr class="dashboard-hardware-skeleton-row" aria-hidden="true">
                            <td colspan="8">
                                <span class="dashboard-hardware-skeleton-row__bar"></span>
                            </td>
                        </tr>
                    @endfor
                @else
                    @include('dashboard.partials.hardware-workspace-tbody', [
                        'rows' => $rows,
                        'incidentIds' => $incidentIds,
                        'operableFulfilmentIds' => $operableFulfilmentIds,
                        'canOperateHardware' => $canOperateHardware,
                        'isShippedScope' => $isShippedScope,
                        'search' => $search,
                    ])
                @endif
            </tbody>
        </table>
    </div>
    <div data-hardware-pagination-host>
        @unless($deferRows)
            @include('dashboard.partials.hardware-workspace-pagination', [
                'hardwareWorkspace' => $hardwareWorkspace,
            ])
        @endunless
    </div>
</div>
