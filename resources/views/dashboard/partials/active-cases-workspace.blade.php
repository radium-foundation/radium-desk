@php
    $filters = $filters ?? [];
    $categories = $categories ?? collect();
    $formAction = $formAction ?? route('dashboard.workspace');
    $clearUrl = $clearUrl ?? route('dashboard.workspace', [
        'workspace' => 'active_cases',
        'status' => 'active',
    ]);
    $workspace = $workspace ?? 'active_cases';
    $hasAdvancedFilters = filled($filters['order_id'] ?? null)
        || filled($filters['category'] ?? null)
        || (filled($filters['status'] ?? null) && ($filters['status'] ?? '') !== 'active')
        || filled($filters['source'] ?? null)
        || filled($filters['date_from'] ?? null)
        || filled($filters['date_to'] ?? null);
    $columnCount = 8;
@endphp

<div class="card border-0 shadow-sm dashboard-service-cases-card dashboard-ops-workspace-card"
     data-operations-embedded-panel="active_cases"
     data-operations-widget="active-cases">
    <div class="card-header bg-white dashboard-cases-card-header">
        <div class="dashboard-cases-header">
            <div class="dashboard-cases-header__title-row">
                <div class="dashboard-cases-header__brand">
                    <span class="dashboard-cases-header__icon" aria-hidden="true">
                        <i class="bi bi-clipboard-data"></i>
                    </span>
                    <h2 class="dashboard-cases-title mb-0">Active Service Cases</h2>
                </div>
                <div class="dashboard-cases-header__actions d-flex align-items-center gap-2">
                    @can('create', App\Models\Incident::class)
                        <a href="{{ route('incidents.create') }}"
                           class="btn btn-sm btn-primary dashboard-btn-compact dashboard-u-focus-ring">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            {{ config('ui.service_case.log_action') }}
                        </a>
                    @endcan
                    @can('viewAny', App\Models\Incident::class)
                        <a href="{{ route('incidents.index', ['status' => 'active']) }}"
                           class="dashboard-cases-view-all dashboard-u-focus-ring">
                            {{ config('ui.service_case.view_all') }}
                            <i class="bi bi-chevron-right" aria-hidden="true"></i>
                        </a>
                    @endcan
                </div>
            </div>

            <div class="dashboard-cases-toolbar">
                <form method="GET"
                      action="{{ $formAction }}"
                      class="dashboard-ops-filter"
                      data-operations-embedded-form="active_cases">
                    <input type="hidden" name="workspace" value="{{ $workspace }}">
                    @unless($hasAdvancedFilters)
                        <input type="hidden" name="status" value="active">
                    @endunless

                    <div class="dashboard-ops-filter__primary">
                        <div class="dashboard-quick-filter dashboard-quick-filter--always-open">
                            <label for="ops-active-cases-search" class="visually-hidden">Search service cases</label>
                            <div class="dashboard-quick-filter__control">
                                <span class="dashboard-quick-filter__icon" aria-hidden="true">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="search"
                                       id="ops-active-cases-search"
                                       name="reference_no"
                                       class="dashboard-quick-filter__input dashboard-u-focus-ring"
                                       value="{{ $filters['reference_no'] ?? '' }}"
                                       placeholder="Search case reference…"
                                       autocomplete="off">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary dashboard-btn-compact dashboard-u-focus-ring">
                            Search
                        </button>
                        <a href="{{ $clearUrl }}"
                           class="btn btn-sm btn-outline-secondary dashboard-btn-compact dashboard-u-focus-ring"
                           data-operations-embedded-clear="active_cases">Clear</a>
                    </div>

                    <details class="dashboard-ops-filter__advanced" @if($hasAdvancedFilters) open @endif>
                        <summary class="dashboard-ops-filter__advanced-toggle">Advanced filters</summary>
                        <div class="dashboard-ops-filter__advanced-grid">
                            <div>
                                <label for="ops-active-order-id" class="form-label">Order ID</label>
                                <input type="text"
                                       name="order_id"
                                       id="ops-active-order-id"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['order_id'] ?? '' }}"
                                       placeholder="Order ID">
                            </div>
                            <div>
                                <label for="ops-active-category" class="form-label">Category</label>
                                <select name="category" id="ops-active-category" class="form-select form-select-sm">
                                    <option value="">All categories</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>
                                            {{ $category }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ops-active-status" class="form-label">Status</label>
                                <select name="status" id="ops-active-status" class="form-select form-select-sm">
                                    <option value="active" @selected(($filters['status'] ?? 'active') === 'active')>Active</option>
                                    <option value="" @selected(($filters['status'] ?? '') === '')>All statuses</option>
                                    @foreach(\App\Enums\IncidentStatus::cases() as $status)
                                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                            {{ $status->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ops-active-source" class="form-label">Source</label>
                                <select name="source" id="ops-active-source" class="form-select form-select-sm">
                                    <option value="">All sources</option>
                                    @foreach(\App\Enums\IncidentSource::cases() as $source)
                                        <option value="{{ $source->value }}" @selected(($filters['source'] ?? '') === $source->value)>
                                            {{ $source->label() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="ops-active-date-from" class="form-label">Date From</label>
                                <input type="date"
                                       name="date_from"
                                       id="ops-active-date-from"
                                       class="form-control form-control-sm"
                                       value="{{ $filters['date_from'] ?? '' }}">
                            </div>
                            <div>
                                <label for="ops-active-date-to" class="form-label">Date To</label>
                                <input type="date"
                                       name="date_to"
                                       id="ops-active-date-to"
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
        <div class="dashboard-cases-table-wrap @if($incidents->isEmpty()) dashboard-cases-table-wrap--empty @endif">
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
                        <th>{{ config('ui.service_case.reference_short') }}</th>
                        <th>Order</th>
                        <th>Title</th>
                        <th class="d-none d-md-table-cell">Category</th>
                        <th class="d-none d-md-table-cell">Source</th>
                        <th class="status-cell">Status</th>
                        <th class="d-none d-lg-table-cell">Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($incidents as $incident)
                        @include('dashboard.partials.active-cases-row', ['incident' => $incident])
                    @empty
                        <tr>
                            <td colspan="{{ $columnCount }}" class="dashboard-cases-empty">
                                {{ config('ui.service_case.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($incidents->hasPages())
            <div class="dashboard-service-cases-footer border-top bg-white px-3 py-2"
                 data-operations-embedded-pagination="active_cases">
                {{ $incidents->links() }}
            </div>
        @endif
    </div>
</div>
