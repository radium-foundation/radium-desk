@extends('layouts.app')

@section('title', 'Refunds')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Refunds</h1>
            <p class="text-muted mb-0">Track and review refund requests.</p>
        </div>
        @can('create', App\Models\RefundRequest::class)
            <a href="{{ route('refunds.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Create Refund Request
            </a>
        @endcan
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h2 class="h6 mb-0">Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('refunds.index') }}" class="row g-3">
                <div class="col-md-6 col-lg-4">
                    <label for="filter_reference_no" class="form-label">Refund Reference Number</label>
                    <input type="text" name="reference_no" id="filter_reference_no" class="form-control"
                           value="{{ $filters['reference_no'] ?? '' }}" placeholder="e.g. REF-2026-000001">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_order_id" class="form-label">Order ID</label>
                    <input type="text" name="order_id" id="filter_order_id" class="form-control"
                           value="{{ $filters['order_id'] ?? '' }}" placeholder="Search order ID">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_incident_reference_no" class="form-label">Incident Reference Number</label>
                    <input type="text" name="incident_reference_no" id="filter_incident_reference_no" class="form-control"
                           value="{{ $filters['incident_reference_no'] ?? '' }}" placeholder="e.g. INC-2026-000001">
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_status" class="form-label">Status</label>
                    <select name="status" id="filter_status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach(\App\Enums\RefundStatus::cases() as $status)
                            <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4">
                    <label for="filter_requested_by" class="form-label">Requested By</label>
                    <select name="requested_by" id="filter_requested_by" class="form-select">
                        <option value="">All requesters</option>
                        @foreach($requesters as $requester)
                            <option value="{{ $requester->id }}" @selected((string) ($filters['requested_by'] ?? '') === (string) $requester->id)>
                                {{ $requester->name }}
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
                    <a href="{{ route('refunds.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($refunds->isEmpty())
                <div class="p-4 text-center text-muted">
                    No refund requests found.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Refund Ref</th>
                                <th>Order ID</th>
                                <th>Incident Ref</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Requested By</th>
                                <th>Requested Date</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($refunds as $refund)
                                <tr>
                                    <td class="fw-semibold">
                                        <a href="{{ route('refunds.show', $refund) }}" class="text-decoration-none">
                                            {{ $refund->reference_no }}
                                        </a>
                                    </td>
                                    <td>
                                        @if($refund->order)
                                            <a href="{{ route('orders.show', $refund->order) }}" class="text-decoration-none">
                                                {{ $refund->order->order_id }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @if($refund->incident)
                                            <a href="{{ route('incidents.show', $refund->incident) }}" class="text-decoration-none">
                                                {{ $refund->incident->reference_no }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ number_format($refund->amount, 2) }}</td>
                                    <td>@include('refunds.partials.status-badge', ['status' => $refund->status])</td>
                                    <td>{{ $refund->requester?->name ?? '—' }}</td>
                                    <td>{{ $refund->created_at?->format('d M Y, H:i') ?: '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('refunds.show', $refund) }}" class="btn btn-sm btn-outline-primary" title="View">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($refunds->hasPages())
            <div class="card-footer bg-white">
                {{ $refunds->links() }}
            </div>
        @endif
    </div>
@endsection
