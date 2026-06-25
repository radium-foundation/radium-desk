@extends('layouts.app')

@section('title', config('ui.service_case.plural'))

@section('content')
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

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('incidents.index') }}" class="row g-3">
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
                    <a href="{{ route('incidents.index') }}" class="btn btn-outline-secondary">Clear</a>
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
                                <th>Order ID</th>
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
                                            <a href="{{ route('orders.show', $incident->order) }}" class="text-decoration-none">
                                                {{ $incident->order->order_id }}
                                            </a>
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
            <div class="card-footer bg-white">
                {{ $incidents->links() }}
            </div>
        @endif
    </div>
@endsection
