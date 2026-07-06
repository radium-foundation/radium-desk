@php
    use App\Services\DashboardPersonalizationService;

    $activeQueue = $operationQueue ?? DashboardPersonalizationService::QUEUE_ATTENTION;
    $legacyServiceCaseFilter = $serviceCaseFilter ?? $activeQueue;
    $serviceCaseFilterCounts = $serviceCaseFilterCounts ?? [];
    $renderedServiceCaseCount = $recentServiceCases->count();
    $totalServiceCaseCount = $serviceCaseTotalCount ?? ($serviceCaseFilterCounts[$legacyServiceCaseFilter] ?? $renderedServiceCaseCount);
    $serviceCaseHasMore = $serviceCaseHasMore ?? ($renderedServiceCaseCount < $totalServiceCaseCount);
    $availableOperationQueues = $availableOperationQueues ?? [];
    $operationQueues = $operationQueues ?? config('operations.queues', []);
    $personalization = app(DashboardPersonalizationService::class);
    $defaultQueue = $personalization->defaultQueueFor(auth()->user());
    $myWorkSearchPlaceholder = 'Search order ID, case ID, serial, customer…';
    $searchPlaceholder = $activeQueue === DashboardPersonalizationService::QUEUE_MY_WORK
        ? $myWorkSearchPlaceholder
        : 'Search service cases…';

    $queueUrl = function (string $queueKey) use ($defaultQueue): string {
        $params = [];

        if ($queueKey !== $defaultQueue) {
            $params['queue'] = $queueKey;
        }

        return route('dashboard', $params);
    };
@endphp

<div class="card border-0 shadow-sm dashboard-service-cases-card"
     id="dashboard-service-cases-panel"
     data-service-cases-loaded="{{ $renderedServiceCaseCount }}"
     data-service-case-filter-total="{{ $totalServiceCaseCount }}"
     data-service-case-filter="{{ $legacyServiceCaseFilter }}"
     data-operation-queue="{{ $activeQueue }}">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <div class="dashboard-cases-header__brand">
                    <span class="dashboard-cases-header__icon" aria-hidden="true">
                        <i class="bi bi-clipboard-data"></i>
                    </span>
                    <h2 class="dashboard-cases-title mb-0">{{ $serviceCasePanelTitle ?? 'Service Cases' }}</h2>
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
                                Assign Ref. No.
                            </button>
                            <span class="dashboard-bulk-toolbar__selection d-none"
                                  data-bulk-selected-label
                                  aria-live="polite">
                                <span class="dashboard-bulk-toolbar__selection-check" aria-hidden="true">☑</span>
                                <span class="dashboard-bulk-toolbar__selection-count" data-bulk-count>0</span>
                                <span class="dashboard-bulk-toolbar__selection-label">selected</span>
                            </span>
                        </div>
                        <span class="visually-hidden" data-bulk-idle-hint>
                            Select one or more rows for batch actions.
                        </span>
                    </div>
                @endif

                @if($showsQueueNavigation ?? true)
                    <div class="dashboard-case-filters dashboard-operation-queues"
                         role="tablist"
                         aria-label="Operational queues">
                        @foreach($operationQueues as $queueKey => $queueMeta)
                            @continue(! in_array($queueKey, $availableOperationQueues, true))
                            @php
                                $isActiveQueue = $activeQueue === $queueKey;
                                $filterCountKey = $isActiveQueue && $legacyServiceCaseFilter !== $queueKey
                                    ? $legacyServiceCaseFilter
                                    : $queueKey;
                            @endphp
                            <a href="{{ $queueUrl($queueKey) }}"
                               @class([
                                   'dashboard-case-filter-chip',
                                   'dashboard-case-filter-chip--' . ($queueMeta['tone'] ?? 'primary'),
                                   'is-active' => $isActiveQueue,
                               ])
                               role="tab"
                               @if($isActiveQueue) aria-selected="true" @else aria-selected="false" @endif
                               @if($isActiveQueue) aria-current="page" @endif>
                                <i class="bi {{ $queueMeta['icon'] ?? 'bi-inbox' }} dashboard-case-filter-chip__icon" aria-hidden="true"></i>
                                <span class="dashboard-case-filter-chip__label">{{ $queueMeta['label'] ?? $queueKey }}</span>
                                <span class="dashboard-case-filter-chip__count"
                                      data-dashboard-case-filter-count="{{ $filterCountKey }}">({{ $serviceCaseFilterCounts[$filterCountKey] ?? 0 }})</span>
                            </a>
                        @endforeach
                    </div>
                @endif

                <div class="dashboard-quick-filter" data-dashboard-quick-filter>
                    <label for="dashboard-quick-filter-input" class="visually-hidden">Quick Filter</label>
                    <button type="button"
                            class="dashboard-quick-filter__summary dashboard-u-focus-ring"
                            data-dashboard-quick-filter-trigger
                            aria-expanded="false"
                            aria-controls="dashboard-quick-filter-control">
                        <span id="dashboard-quick-filter-count"
                              data-dashboard-filter-count
                              aria-live="polite">{{ $renderedServiceCaseCount }} of {{ $totalServiceCaseCount }} Showing</span>
                    </button>
                    <div class="dashboard-quick-filter__control d-none"
                         id="dashboard-quick-filter-control"
                         data-dashboard-quick-filter-control>
                        <span class="dashboard-quick-filter__icon" aria-hidden="true">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="search"
                               id="dashboard-quick-filter-input"
                               class="dashboard-quick-filter__input dashboard-u-focus-ring"
                               placeholder="{{ $searchPlaceholder }}"
                               autocomplete="off"
                               data-dashboard-quick-filter-input
                               aria-describedby="dashboard-quick-filter-count">
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
                            <th class="d-none d-md-table-cell">People</th>
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
                                $tableColumnCount = 11
                                    + (($canManageTransactions ?? false) ? 1 : 0)
                                    + (($canShowServiceCaseActions ?? false) ? 1 : 0);
                            @endphp
                            <tr id="dashboard-service-cases-empty-row">
                                <td colspan="{{ $tableColumnCount }}" class="dashboard-cases-empty">
                                    No service cases match this queue.
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
