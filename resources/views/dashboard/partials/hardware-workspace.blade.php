@php
    use App\Enums\HardwareWorkspaceScope;

    $deferRows = (bool) ($hardwareWorkspace['defer_rows'] ?? false);
    $rows = $hardwareWorkspace['rows'] ?? collect();
    $incidentIds = $hardwareWorkspace['incidentIds'] ?? [];
    $operableFulfilmentIds = $hardwareWorkspace['operableFulfilmentIds'] ?? [];
    $canOperateHardware = (bool) ($hardwareWorkspace['canOperateHardware'] ?? false);
    $canDownloadDocuments = (bool) ($hardwareWorkspace['canDownloadDocuments'] ?? false);
    $search = $hardwareWorkspace['search'] ?? '';
    $isShippedScope = ($hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value) === HardwareWorkspaceScope::Shipped->value;
    $skeletonCount = (int) ($hardwareWorkspace['skeleton_row_count'] ?? $hardwareWorkspace['initial_per_page'] ?? 10);
    $initialPerPage = (int) ($hardwareWorkspace['initial_per_page'] ?? 10);
    $searchDebounceMs = (int) ($hardwareWorkspace['search_debounce_ms'] ?? 300);
@endphp

<div id="dashboard-hardware-workspace"
     data-hardware-workspace
     data-hardware-defer-rows="{{ $deferRows ? '1' : '0' }}"
     data-hardware-rows-url="{{ route('dashboard.hardware.rows') }}"
     data-hardware-counts-url="{{ route('dashboard.hardware.counts') }}"
     data-hardware-shiprocket-url="{{ route('dashboard.hardware.shiprocket-balance') }}"
     data-hardware-scope="{{ $hardwareWorkspace['scope'] ?? HardwareWorkspaceScope::Active->value }}"
     data-hardware-filter="{{ $hardwareWorkspace['filter'] ?? 'needs_action' }}"
     data-hardware-page="{{ $hardwareWorkspace['page'] ?? 1 }}"
     data-hardware-per-page="{{ $hardwareWorkspace['per_page'] ?? 40 }}"
     data-hardware-initial-per-page="{{ $initialPerPage }}"
     data-hardware-search-debounce-ms="{{ $searchDebounceMs }}"
     data-hardware-progressive="1"
     data-hardware-first-chunk="{{ max(1, (int) config('dashboard.hardware_rows_first_chunk', 4)) }}"
     data-hardware-row-chunk="{{ max(1, (int) config('dashboard.hardware_rows_chunk_size', 3)) }}"
     data-hardware-page-cache-max="{{ max(2, (int) config('dashboard.hardware_rows_page_cache_max', 6)) }}"
     data-hardware-inspected-count="{{ $hardwareWorkspace['inspected_fulfilment_count'] ?? 0 }}"
     @if($canDownloadDocuments)
         data-hardware-download-labels-url="{{ route('inventory.hardware-fulfilments.bulk.download.labels') }}"
         data-hardware-download-manifests-url="{{ route('inventory.hardware-fulfilments.bulk.download.manifests') }}"
         data-hardware-download-package-url="{{ route('inventory.hardware-fulfilments.bulk.download.package') }}"
     @endif
     @if($search !== '') data-hardware-search="{{ $search }}" @endif>
    <div class="dashboard-hardware-selection d-none" data-hardware-selection-bar hidden>
        <span class="dashboard-hardware-selection__count" data-hardware-selection-count>0 selected</span>
        <span class="dashboard-hardware-selection__eligible text-muted small d-none" data-hardware-selection-eligible></span>
        @if($canDownloadDocuments)
            <button type="button"
                    class="btn btn-sm btn-outline-primary dashboard-btn-compact"
                    data-hardware-download-labels
                    disabled
                    title="Download existing label PDFs only (does not generate new labels)">
                Download labels
            </button>
            <button type="button"
                    class="btn btn-sm btn-outline-primary dashboard-btn-compact"
                    data-hardware-download-manifests
                    disabled
                    title="Download existing manifest PDFs only (does not generate manifests)">
                Download manifests
            </button>
            <button type="button"
                    class="btn btn-sm btn-primary dashboard-btn-compact"
                    data-hardware-download-package
                    disabled
                    title="ZIP of existing labels, manifests, and reconciliation.csv">
                Download package
            </button>
        @endif
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

    @if($canDownloadDocuments)
        <div class="dashboard-hardware-bulk-download-dialog card shadow-sm d-none"
             data-hardware-bulk-download-dialog
             hidden
             role="dialog"
             aria-labelledby="hardware-bulk-download-title">
            <div class="card-body">
                <h2 class="h6" id="hardware-bulk-download-title" data-hardware-bulk-download-title>Bulk download</h2>
                <p class="small text-muted mb-2" data-hardware-bulk-download-summary></p>
                <div class="table-responsive" style="max-height: 280px; overflow: auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Order</th>
                                <th scope="col">Result</th>
                                <th scope="col">Reason</th>
                            </tr>
                        </thead>
                        <tbody data-hardware-bulk-download-rows></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-hardware-bulk-download-close>
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

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
    <div class="dashboard-hardware-page-loading d-none"
         data-hardware-page-loading
         role="status"
         aria-live="polite"
         hidden>
        <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
        Loading more orders…
    </div>
</div>
