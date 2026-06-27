@php
    $activeFilter = $serviceCaseFilter ?? 'pending_admin';
    $dashboardService = app(\App\Services\DashboardService::class);
    $serviceCaseFilterCounts = $dashboardService->serviceCaseFilterCounts();
    $serviceCaseFilterMeta = [
        'all' => ['label' => 'All', 'icon' => 'bi-grid-3x3-gap-fill', 'tone' => 'primary'],
        'pending_admin' => ['label' => 'Pending Admin', 'icon' => 'bi-clock', 'tone' => 'warning'],
        'completed' => ['label' => 'Completed', 'icon' => 'bi-check-circle-fill', 'tone' => 'success'],
        'high_priority' => ['label' => 'High Priority', 'icon' => 'bi-flag-fill', 'tone' => 'danger'],
    ];
@endphp

<div class="card border-0 shadow-sm dashboard-service-cases-card">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <div class="dashboard-cases-header__brand">
                    <span class="dashboard-cases-header__icon" aria-hidden="true">
                        <i class="bi bi-clipboard-data"></i>
                    </span>
                    <h2 class="dashboard-cases-title mb-0">Recent Service Cases</h2>
                </div>
                @can('viewAny', App\Models\Incident::class)
                    <a href="{{ route('incidents.index') }}"
                       class="dashboard-cases-view-all dashboard-u-focus-ring">
                        {{ config('ui.service_case.view_all') }}
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                @endcan
            </div>

            <div class="dashboard-cases-toolbar">
                @if($canManageTransactions ?? false)
                    <div class="dashboard-bulk-toolbar"
                         data-bulk-bar
                         role="region"
                         aria-label="Batch service case actions">
                        <div class="dashboard-bulk-toolbar__actions">
                            <button type="button"
                                    class="btn btn-sm btn-primary dashboard-btn-compact dashboard-bulk-toolbar__assign"
                                    data-batch-assign
                                    disabled>
                                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                                Assign Transaction ID
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary dashboard-btn-compact dashboard-bulk-toolbar__clear d-none"
                                    data-batch-clear
                                    disabled>
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                Clear Selection
                            </button>
                        </div>
                        <span class="visually-hidden" data-bulk-idle-hint>
                            Select one or more rows for batch actions.
                        </span>
                        <span class="visually-hidden" data-bulk-selected-label>
                            Selected: <span data-bulk-count>0</span>
                        </span>
                    </div>
                @endif

                <div class="dashboard-case-filters"
                     role="group"
                     aria-label="Service case filters">
                    @foreach($serviceCaseFilterMeta as $filterKey => $filterMeta)
                        <a href="{{ route('dashboard', ['filter' => $filterKey]) }}"
                           @class([
                               'dashboard-case-filter-chip',
                               'dashboard-case-filter-chip--' . $filterMeta['tone'],
                               'is-active' => $activeFilter === $filterKey,
                           ])
                           @if($activeFilter === $filterKey) aria-current="page" @endif>
                            <i class="bi {{ $filterMeta['icon'] }} dashboard-case-filter-chip__icon" aria-hidden="true"></i>
                            <span class="dashboard-case-filter-chip__label">{{ $filterMeta['label'] }}</span>
                            <span class="dashboard-case-filter-chip__count"
                                  data-dashboard-case-filter-count="{{ $filterKey }}">({{ $serviceCaseFilterCounts[$filterKey] }})</span>
                        </a>
                    @endforeach
                </div>

                <div class="dashboard-quick-filter" data-dashboard-quick-filter>
                    <label for="dashboard-quick-filter-input" class="visually-hidden">Quick Filter</label>
                    <div class="dashboard-quick-filter__control">
                        <span class="dashboard-quick-filter__icon" aria-hidden="true">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="search"
                               id="dashboard-quick-filter-input"
                               class="dashboard-quick-filter__input dashboard-u-focus-ring"
                               placeholder="Filter current list..."
                               autocomplete="off"
                               data-dashboard-quick-filter-input
                               aria-describedby="dashboard-quick-filter-count">
                        <span id="dashboard-quick-filter-count"
                              class="dashboard-quick-filter__count"
                              data-dashboard-filter-count
                              aria-live="polite">0 / 0</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0 position-relative">
        <div id="dashboard-service-cases-content">
            <div class="dashboard-cases-table-wrap" id="dashboard-service-cases-scroll">
                <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table">
                    <thead class="table-light">
                        <tr>
                            @if($canManageTransactions)
                                <th class="dashboard-select-cell">
                                    <input type="checkbox"
                                           class="form-check-input"
                                           data-select-all
                                           aria-label="Select all pending service cases">
                                </th>
                            @endif
                            <th>{{ config('ui.service_case.reference_short') }}</th>
                            <th>Order ID</th>
                            <th>Serial</th>
                            <th>Status</th>
                            <th class="sla-cell">SLA</th>
                            <th>Txn ID</th>
                            <th class="d-none d-md-table-cell">Source</th>
                            <th class="d-none d-md-table-cell">Owner</th>
                            <th class="d-none d-md-table-cell">Logged By</th>
                            <th class="d-none d-lg-table-cell">Created</th>
                            <th class="d-none d-lg-table-cell">Updated</th>
                            <th class="d-none d-lg-table-cell">Product</th>
                            @if($canShowServiceCaseActions ?? false)
                                <th class="dashboard-actions-cell text-end">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody id="dashboard-service-cases-body">
                        @forelse($recentServiceCases as $serviceCase)
                            @include('dashboard.partials.service-case-row', [
                                'serviceCase' => $serviceCase,
                                'canManageTransactions' => $canManageTransactions,
                                'canSelectRows' => $canManageTransactions,
                                'canReassignServiceCases' => $canReassignServiceCases ?? false,
                                'canCreateRemarks' => $canCreateRemarks ?? false,
                            ])
                        @empty
                            @php
                                $tableColumnCount = 12
                                    + (($canManageTransactions ?? false) ? 1 : 0)
                                    + (($canShowServiceCaseActions ?? false) ? 1 : 0);
                            @endphp
                            <tr id="dashboard-service-cases-empty-row">
                                <td colspan="{{ $tableColumnCount }}" class="dashboard-cases-empty">
                                    No service cases match this filter.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
