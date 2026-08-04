@php
    $embedded = $embedded ?? false;
    $formAction = $formAction ?? route('incidents.index');
    $clearUrl = $clearUrl ?? route('incidents.index');
    $filters = $filters ?? [];
    $workspace = $workspace ?? 'active_cases';
@endphp

@if($embedded)
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h2 class="h5 mb-0">{{ config('ui.service_case.plural') }}</h2>
            <p class="text-muted small mb-0">Active and filtered service cases.</p>
        </div>
        @can('create', App\Models\Incident::class)
            <a href="{{ route('incidents.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i> {{ config('ui.service_case.log_action') }}
            </a>
        @endcan
    </div>
@else
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">{{ config('ui.service_case.plural') }}</h1>
            <p class="text-muted mb-0">Track and manage service desk service cases.</p>
        </div>
        @can('create', App\Models\Incident::class)
            <a href="{{ route('incidents.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> {{ config('ui.service_case.log_action') }}
            </a>
        @endcan
    </div>
@endif

<div class="card border-0 shadow-sm mb-4"
     @if($embedded) data-operations-embedded-panel="active_cases" @endif>
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Filters</h2>
    </div>
    <div class="card-body">
        <form method="GET"
              action="{{ $formAction }}"
              class="row g-3"
              @if($embedded) data-operations-embedded-form="active_cases" @endif>
            @if($embedded)
                <input type="hidden" name="workspace" value="{{ $workspace }}">
            @endif
            <div class="col-md-6 col-lg-4">
                <label for="filter_order_id" class="form-label">Order ID</label>
                <input type="text" name="order_id" id="filter_order_id" class="form-control"
                       value="{{ $filters['order_id'] ?? '' }}" placeholder="Search order ID">
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_reference_no" class="form-label">{{ config('ui.service_case.reference_label') }}</label>
                <input type="text" name="reference_no" id="filter_reference_no" class="form-control"
                       value="{{ $filters['reference_no'] ?? '' }}" placeholder="e.g. SC-00001">
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_category" class="form-label">Category</label>
                <select name="category" id="filter_category" class="form-select">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>
                            {{ $category }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_status" class="form-label">Status</label>
                <select name="status" id="filter_status" class="form-select">
                    <option value="">All statuses</option>
                    @foreach(\App\Enums\IncidentStatus::cases() as $status)
                        <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                            {{ $status->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_source" class="form-label">Source</label>
                <select name="source" id="filter_source" class="form-select">
                    <option value="">All sources</option>
                    @foreach(\App\Enums\IncidentSource::cases() as $source)
                        <option value="{{ $source->value }}" @selected(($filters['source'] ?? '') === $source->value)>
                            {{ $source->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_date_from" class="form-label">Date From</label>
                <input type="date" name="date_from" id="filter_date_from" class="form-control"
                       value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-md-6 col-lg-4">
                <label for="filter_date_to" class="form-label">Date To</label>
                <input type="date" name="date_to" id="filter_date_to" class="form-control"
                       value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <a href="{{ $clearUrl }}"
                   class="btn btn-outline-secondary"
                   @if($embedded) data-operations-embedded-clear="active_cases" @endif>Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        @if($incidents->isEmpty())
            <div class="p-4 text-center text-muted">{{ config('ui.service_case.empty') }}</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>{{ config('ui.service_case.reference_short') }}</th>
                            <th>Order</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($incidents as $incident)
                            <tr>
                                <td class="fw-semibold">
                                    <a href="{{ route('incidents.show', $incident) }}" class="text-decoration-none">
                                        {{ $incident->display_reference }}
                                    </a>
                                </td>
                                <td>
                                    @if($incident->order)
                                        <x-order-identifier
                                            :order="$incident->order"
                                            :incident="$incident"
                                            :href="$incident->order->isInquiryOrder() ? null : route('orders.show', $incident->order)"
                                        />
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ Str::limit($incident->title, 40) }}</td>
                                <td>{{ $incident->category }}</td>
                                <td>{{ $incident->source->label() }}</td>
                                <td>
                                    @include('incidents.partials.status-badge', ['status' => $incident->status])
                                </td>
                                <td class="text-nowrap">{{ display_app_date($incident->created_at) }}</td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('incidents.show', $incident) }}" class="btn btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        @can('update', $incident)
                                            <a href="{{ route('incidents.edit', $incident) }}" class="btn btn-outline-secondary" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
    @if($incidents->hasPages())
        <div class="card-footer bg-white"
             @if($embedded) data-operations-embedded-pagination="active_cases" @endif>
            {{ $incidents->links() }}
        </div>
    @endif
</div>
