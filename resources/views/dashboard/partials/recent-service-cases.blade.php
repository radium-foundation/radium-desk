@php
    use App\Services\DashboardPersonalizationService;

    $activeFilter = $serviceCaseFilter ?? 'pending_admin';
    $serviceCaseFilterCounts = $serviceCaseFilterCounts ?? [];
    $renderedServiceCaseCount = $recentServiceCases->count();
    $totalServiceCaseCount = $serviceCaseTotalCount ?? ($serviceCaseFilterCounts[$activeFilter] ?? $renderedServiceCaseCount);
    $serviceCaseHasMore = $serviceCaseHasMore ?? ($renderedServiceCaseCount < $totalServiceCaseCount);
    $availableServiceCaseFilters = $availableServiceCaseFilters ?? ['all', 'pending_admin', 'completed', 'high_priority'];
    $dashboardView = $dashboardView ?? DashboardPersonalizationService::VIEW_ALL;
    $personalization = app(DashboardPersonalizationService::class);
    $defaultView = $personalization->defaultViewFor(auth()->user());
    $serviceCaseFilterMeta = [
        'all' => ['label' => 'All', 'icon' => 'bi-grid-3x3-gap-fill', 'tone' => 'primary'],
        'pending_admin' => ['label' => 'Pending Admin', 'icon' => 'bi-clock', 'tone' => 'warning'],
        'pending_support' => ['label' => 'Unassigned', 'icon' => 'bi-headset', 'tone' => 'warning'],
        'completed' => ['label' => 'Completed', 'icon' => 'bi-check-circle-fill', 'tone' => 'success'],
        'high_priority' => ['label' => 'High Priority', 'icon' => 'bi-flag-fill', 'tone' => 'danger'],
        'needs_attention' => ['label' => 'Needs Attention', 'icon' => 'bi-exclamation-triangle-fill', 'tone' => 'warning'],
        'my_cases' => ['label' => 'My Cases', 'icon' => 'bi-person-fill', 'tone' => 'primary'],
    ];

    $filterUrl = function (string $filterKey) use ($dashboardView, $defaultView): string {
        $params = [];

        if ($dashboardView !== $defaultView) {
            $params['view'] = $dashboardView;
        }

        if ($filterKey !== app(DashboardPersonalizationService::class)->defaultFilterFor(auth()->user(), $dashboardView)) {
            $params['filter'] = $filterKey;
        }

        return route('dashboard', $params);
    };
@endphp

<div class="card border-0 shadow-sm dashboard-service-cases-card"
     id="dashboard-service-cases-panel"
     data-service-cases-loaded="{{ $renderedServiceCaseCount }}"
     data-service-case-filter-total="{{ $totalServiceCaseCount }}"
     data-service-case-filter="{{ $activeFilter }}">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <div class="dashboard-cases-header__brand">
                    <span class="dashboard-cases-header__icon" aria-hidden="true">
                        <i class="bi bi-clipboard-data"></i>
                    </span>
                    <h2 class="dashboard-cases-title mb-0">{{ $serviceCasePanelTitle ?? 'Recent Service Cases' }}</h2>
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
                                Assign Service Reference
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
                        @continue(! in_array($filterKey, $availableServiceCaseFilters, true))
                        <a href="{{ $filterUrl($filterKey) }}"
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
                               placeholder="Search by phone, order, serial, case ID..."
                               autocomplete="off"
                               data-dashboard-quick-filter-input
                               aria-describedby="dashboard-quick-filter-count">
                        <span id="dashboard-quick-filter-count"
                              class="dashboard-quick-filter__count"
                              data-dashboard-filter-count
                              aria-live="polite">Showing {{ $renderedServiceCaseCount }} of {{ $totalServiceCaseCount }} service {{ $totalServiceCaseCount === 1 ? 'case' : 'cases' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0 position-relative">
        <div id="dashboard-service-cases-content">
            <div class="dashboard-search-banner d-none"
                 data-dashboard-search-banner
                 hidden
                 role="status"
                 aria-live="polite">
                <div class="dashboard-search-banner__content">
                    <div>
                        <strong class="dashboard-search-banner__title"
                                data-dashboard-search-banner-title>Search Results</strong>
                        <p class="dashboard-search-banner__message mb-0"
                           data-dashboard-search-banner-message></p>
                    </div>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            data-dashboard-search-clear>
                        Clear Search
                    </button>
                </div>
            </div>
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
                            <th class="case-serial-cell">Serial</th>
                            <th>Status</th>
                            <th class="sla-cell">SLA</th>
                            <th>Ref.</th>
                            <th class="d-none d-md-table-cell">Source</th>
                            <th class="d-none d-md-table-cell">Owner</th>
                            <th class="d-none d-md-table-cell">Logged By</th>
                            <th class="d-none d-lg-table-cell">Created</th>
                            <th class="d-none d-lg-table-cell">Updated</th>
                            <th class="d-none d-lg-table-cell">Model</th>
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
            <div class="dashboard-service-cases-footer border-top bg-white px-3 py-2 text-center @if(! $serviceCaseHasMore) d-none @endif"
                 data-dashboard-load-more-wrap>
                <button type="button"
                        class="btn btn-sm btn-outline-primary dashboard-u-focus-ring"
                        data-dashboard-load-more>
                    Load More
                </button>
            </div>
        </div>
    </div>
</div>
