<div class="card border-0 shadow-sm dashboard-service-cases-card">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <h2 class="dashboard-cases-title mb-0">Recent Service Cases</h2>
                @can('viewAny', App\Models\Incident::class)
                    <a href="{{ route('incidents.index') }}" class="btn btn-sm btn-outline-primary dashboard-btn-compact">{{ config('ui.service_case.view_all') }}</a>
                @endcan
            </div>

            @if($canManageTransactions ?? false)
                <div class="dashboard-bulk-toolbar"
                     data-bulk-bar
                     role="region"
                     aria-label="Batch service case actions">
                    <span class="dashboard-bulk-toolbar__status">
                        <span class="dashboard-bulk-toolbar__hint" data-bulk-idle-hint>
                            Select one or more rows for batch actions.
                        </span>
                        <span class="dashboard-bulk-toolbar__count d-none" data-bulk-selected-label>
                            Selected: <span data-bulk-count>0</span>
                        </span>
                    </span>
                    <div class="dashboard-bulk-toolbar__actions">
                        <button type="button"
                                class="btn btn-sm btn-outline-secondary dashboard-btn-compact"
                                data-batch-clear
                                disabled>
                            Clear Selection
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-primary dashboard-btn-compact"
                                data-batch-assign
                                disabled>
                            Assign Transaction ID
                        </button>
                    </div>
                </div>
            @endif

            <div class="btn-group btn-group-sm dashboard-case-filters" role="group" aria-label="Service case filters">
                @foreach([
                    'all' => 'All',
                    'pending_admin' => 'Pending Admin',
                    'completed' => 'Completed',
                    'high_priority' => 'High Priority',
                ] as $filterKey => $filterLabel)
                    <a href="{{ route('dashboard', ['filter' => $filterKey]) }}"
                       @class([
                           'btn',
                           'btn-outline-secondary' => ($serviceCaseFilter ?? 'pending_admin') !== $filterKey,
                           'btn-secondary' => ($serviceCaseFilter ?? 'pending_admin') === $filterKey,
                       ])>
                        {{ $filterLabel }}
                    </a>
                @endforeach
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
                            <th class="d-none d-md-table-cell">{{ config('ui.service_case.reference_short') }}</th>
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
                                <td colspan="{{ $tableColumnCount }}" class="text-center text-muted small py-2">
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
