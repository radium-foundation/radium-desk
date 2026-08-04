@php
    $filters = $filters ?? [];
    $requesters = $requesters ?? collect();
    $queueCounts = $queueCounts ?? [];
    $activeQueue = $activeQueue ?? '';
    $formAction = $formAction ?? route('dashboard.workspace');
    $clearUrl = $clearUrl ?? route('dashboard.workspace', [
        'workspace' => 'refunds',
        'status' => 'pending',
    ]);
    $workspace = $workspace ?? 'refunds';
    $queues = [
        'pending_approval' => [
            'label' => 'Pending Approval',
            'count' => $queueCounts['pending_approval'] ?? 0,
            'tone' => 'warning',
            'icon' => 'bi-hourglass-split',
        ],
        'pending_execution' => [
            'label' => 'Pending Execution',
            'count' => $queueCounts['pending_execution'] ?? 0,
            'tone' => 'primary',
            'icon' => 'bi-cash-coin',
        ],
        'completed_today' => [
            'label' => 'Completed Today',
            'count' => $queueCounts['completed_today'] ?? 0,
            'tone' => 'success',
            'icon' => 'bi-check2-circle',
        ],
        'rejected' => [
            'label' => 'Rejected',
            'count' => $queueCounts['rejected'] ?? 0,
            'tone' => 'danger',
            'icon' => 'bi-x-circle',
        ],
    ];
    $hasAdvancedFilters = filled($filters['order_id'] ?? null)
        || filled($filters['incident_reference_no'] ?? null)
        || filled($filters['requested_by'] ?? null)
        || filled($filters['date_from'] ?? null)
        || filled($filters['date_to'] ?? null)
        || (
            filled($filters['status'] ?? null)
            && ($filters['status'] ?? '') !== 'pending'
            && ($activeQueue ?? '') === ''
        );
    $columnCount = 8;
@endphp

<div class="card border-0 shadow-sm dashboard-service-cases-card dashboard-ops-workspace-card"
     data-operations-embedded-panel="refunds"
     data-operations-widget="refunds">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <div class="dashboard-cases-header__brand">
                    <span class="dashboard-cases-header__icon" aria-hidden="true">
                        <i class="bi bi-cash-stack"></i>
                    </span>
                    <h2 class="dashboard-cases-title mb-0">Refund Queue</h2>
                </div>
                <div class="dashboard-cases-header__actions d-flex align-items-center gap-2">
                    @can('create', App\Models\RefundRequest::class)
                        <a href="{{ route('refunds.create') }}"
                           class="btn btn-sm btn-primary dashboard-btn-compact dashboard-u-focus-ring">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            Request Refund
                        </a>
                    @endcan
                    @can('viewAny', App\Models\RefundRequest::class)
                        <a href="{{ route('refunds.index', ['status' => 'pending']) }}"
                           class="dashboard-cases-view-all dashboard-u-focus-ring">
                            View all
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    @endcan
                </div>
            </div>

            <div class="dashboard-cases-toolbar">
                <div class="dashboard-case-filters"
                     role="tablist"
                     aria-label="Refund queues">
                    @foreach($queues as $queueKey => $queueMeta)
                        @php
                            $isActiveQueue = ($activeQueue ?? '') === $queueKey;
                        @endphp
                        <a href="{{ route('dashboard', ['workspace' => 'refunds', 'queue' => $queueKey]) }}"
                           data-operations-embedded-nav="refunds"
                           @class([
                               'dashboard-case-filter-chip',
                               'dashboard-case-filter-chip--'.$queueMeta['tone'],
                               'is-active' => $isActiveQueue,
                           ])
                           role="tab"
                           @if($isActiveQueue) aria-selected="true" aria-current="page" @else aria-selected="false" @endif>
                            <i class="bi {{ $queueMeta['icon'] }} dashboard-case-filter-chip__icon" aria-hidden="true"></i>
                            <span class="dashboard-case-filter-chip__label">{{ $queueMeta['label'] }}</span>
                            <span class="dashboard-case-filter-chip__count">({{ $queueMeta['count'] }})</span>
                        </a>
                    @endforeach
                </div>

                <form method="GET"
                      action="{{ $formAction }}"
                      class="dashboard-ops-filter"
                      data-operations-embedded-form="refunds">
                    <input type="hidden" name="workspace" value="{{ $workspace }}">
                    @if(filled($activeQueue))
                        <input type="hidden" name="queue" value="{{ $activeQueue }}">
                    @elseif(! $hasAdvancedFilters)
                        <input type="hidden" name="status" value="pending">
                    @endif

                    <div class="dashboard-ops-filter__primary">
                        <div class="dashboard-quick-filter dashboard-quick-filter--always-open">
                            <label for="ops-refunds-search" class="visually-hidden">Search refunds</label>
                            <div class="dashboard-quick-filter__control">
                                <span class="dashboard-quick-filter__icon" aria-hidden="true">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="search"
                                       id="ops-refunds-search"
                                       name="reference_no"
                                       class="dashboard-quick-filter__input dashboard-u-focus-ring"
                                       value="{{ $filters['reference_no'] ?? '' }}"
                                       placeholder="Search refund reference…"
                                       autocomplete="off">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary dashboard-btn-compact dashboard-u-focus-ring">
                            Search
                        </button>
                        <a href="{{ $clearUrl }}"
                           class="btn btn-sm btn-outline-secondary dashboard-btn-compact dashboard-u-focus-ring"
                           data-operations-embedded-clear="refunds">Clear</a>
                    </div>

                    <details class="dashboard-ops-filter__advanced" @if($hasAdvancedFilters) open @endif>
                        <summary class="dashboard-ops-filter__advanced-toggle">Advanced filters</summary>
                        <div class="dashboard-ops-filter__advanced-grid">
                            <div>
                                <label for="ops-refund-order-id" class="form-label">Order ID</label>
                                <input type="text"
                                       name="order_id"
                                       id="ops-refund-order-id"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['order_id'] ?? '' }}"
                                       placeholder="Order ID">
                            </div>
                            <div>
                                <label for="ops-refund-sc-ref" class="form-label">{{ config('ui.service_case.reference_label') }}</label>
                                <input type="text"
                                       name="incident_reference_no"
                                       id="ops-refund-sc-ref"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['incident_reference_no'] ?? '' }}"
                                       placeholder="e.g. SC-00001">
                            </div>
                            <div>
                                <label for="ops-refund-status" class="form-label">Status</label>
                                <select name="status" id="ops-refund-status" class="form-select form-select-sm">
                                    <option value="">All statuses</option>
                                    @foreach(\App\Enums\RefundStatus::cases() as $status)
                                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                            {{ $status->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ops-refund-requested-by" class="form-label">Requested By</label>
                                <select name="requested_by" id="ops-refund-requested-by" class="form-select form-select-sm">
                                    <option value="">All requesters</option>
                                    @foreach($requesters as $requester)
                                        <option value="{{ $requester->id }}" @selected((string) ($filters['requested_by'] ?? '') === (string) $requester->id)>
                                            {{ $requester->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ops-refund-date-from" class="form-label">Date From</label>
                                <input type="date"
                                       name="date_from"
                                       id="ops-refund-date-from"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['date_from'] ?? '' }}">
                            </div>
                            <div>
                                <label for="ops-refund-date-to" class="form-label">Date To</label>
                                <input type="date"
                                       name="date_to"
                                       id="ops-refund-date-to"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['date_to'] ?? '' }}">
                            </div>
                            <div class="dashboard-ops-filter__advanced-actions">
                                <button type="submit" class="btn btn-sm btn-primary dashboard-btn-compact">Apply</button>
                            </div>
                        </div>
                    </details>
                </form>
            </div>
        </div>
    </div>

    <div class="card-body p-0 position-relative">
        <div class="dashboard-cases-table-wrap @if($refunds->isEmpty()) dashboard-cases-table-wrap--empty @endif">
            <div class="dashboard-workspace-skeleton d-none"
                 data-operations-workspace-skeleton
                 aria-hidden="true">
                <div class="dashboard-workspace-skeleton__row"></div>
                <div class="dashboard-workspace-skeleton__row"></div>
                <div class="dashboard-workspace-skeleton__row"></div>
            </div>
            <table class="table table-sm table-hover align-middle mb-0 dashboard-cases-table">
                <thead class="table-light">
                    <tr>
                        <th>Refund Ref</th>
                        <th>Order</th>
                        <th class="d-none d-md-table-cell">{{ config('ui.service_case.reference_short') }}</th>
                        <th>Amount</th>
                        <th class="status-cell">Status</th>
                        <th class="d-none d-md-table-cell">Requested By</th>
                        <th class="d-none d-lg-table-cell">Requested</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($refunds as $refund)
                        @include('dashboard.partials.refund-workspace-row', ['refund' => $refund])
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}" class="dashboard-cases-empty">
                                No refund requests found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($refunds->hasPages())
            <div class="dashboard-service-cases-footer border-top bg-white px-3 py-2"
                 data-operations-embedded-pagination="refunds">
                {{ $refunds->links() }}
            </div>
        @endif
    </div>
</div>
