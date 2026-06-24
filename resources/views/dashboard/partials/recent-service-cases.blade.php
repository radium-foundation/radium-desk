<div class="card border-0 shadow-sm dashboard-service-cases-card"
     data-bulk-url="{{ $canManageTransactions ? route('dashboard.transactions.bulk') : '' }}">
    <div class="card-header bg-white py-2">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="h6 mb-0">Recent Service Cases</h2>
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
            @can('viewAny', App\Models\Incident::class)
                <a href="{{ route('incidents.index') }}" class="btn btn-sm btn-outline-primary">{{ config('ui.service_case.view_all') }}</a>
            @endcan
        </div>
    </div>
    <div class="card-body p-0 position-relative">
        @if($canManageTransactions)
            <div class="dashboard-bulk-bar d-none" data-bulk-bar>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="small fw-semibold">Selected: <span data-bulk-count>0</span></span>
                    <label class="visually-hidden" for="bulk_transaction_id">Transaction ID</label>
                    <input type="text"
                           id="bulk_transaction_id"
                           class="form-control form-control-sm bulk-transaction-input"
                           placeholder="Transaction ID"
                           maxlength="100"
                           data-bulk-transaction-input>
                    <button type="button" class="btn btn-sm btn-primary" data-bulk-apply disabled>
                        Apply
                    </button>
                </div>
            </div>
        @endif

        @if($recentServiceCases->isEmpty())
            <div class="p-3 text-center text-muted small">
                No service cases match this filter.
            </div>
        @else
            <div class="table-responsive">
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
                            <th>Serial Number</th>
                            <th class="d-none d-lg-table-cell">Product</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th class="d-none d-md-table-cell">Transaction ID</th>
                            <th class="d-none d-sm-table-cell">Owner</th>
                            <th class="d-none d-sm-table-cell">Logged By</th>
                            <th>Created</th>
                            <th class="d-none d-md-table-cell">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-service-cases-body">
                        @foreach($recentServiceCases as $serviceCase)
                            @include('dashboard.partials.service-case-row', [
                                'serviceCase' => $serviceCase,
                                'canManageTransactions' => $canManageTransactions,
                                'canSelectRows' => $canManageTransactions,
                            ])
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
