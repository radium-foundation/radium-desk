@extends('layouts.app')

@section('title', $incident->reference_no)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('incidents.index') }}">Incidents</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $incident->reference_no }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">{{ $incident->reference_no }}</h1>
            <p class="text-muted mb-0">{{ $incident->title }}</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @can('update', $incident)
                <a href="{{ route('incidents.edit', $incident) }}" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
            @endcan
            @can('delete', $incident)
                <form method="POST" action="{{ route('incidents.destroy', $incident) }}"
                      onsubmit="return confirm('Are you sure you want to delete this incident?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>
            @endcan
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Incident Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Reference No</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $incident->reference_no }}</dd>

                        <dt class="col-sm-4 text-muted">Status</dt>
                        <dd class="col-sm-8">@include('incidents.partials.status-badge', ['status' => $incident->status])</dd>

                        <dt class="col-sm-4 text-muted">Category</dt>
                        <dd class="col-sm-8">{{ $incident->category }}</dd>

                        <dt class="col-sm-4 text-muted">Source</dt>
                        <dd class="col-sm-8">{{ $incident->source->label() }}</dd>

                        <dt class="col-sm-4 text-muted">Title</dt>
                        <dd class="col-sm-8">{{ $incident->title }}</dd>

                        <dt class="col-sm-4 text-muted">Description</dt>
                        <dd class="col-sm-8">{!! nl2br(e($incident->description)) !!}</dd>

                        <dt class="col-sm-4 text-muted">Logged By</dt>
                        <dd class="col-sm-8">{{ $incident->creator?->name ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Created</dt>
                        <dd class="col-sm-8">{{ $incident->created_at?->format('d M Y, H:i') ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Last Updated</dt>
                        <dd class="col-sm-8">{{ $incident->updated_at?->format('d M Y, H:i') ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Related Order</h2>
                </div>
                <div class="card-body">
                    @if($incident->order)
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Order ID</dt>
                            <dd class="col-sm-8">
                                <a href="{{ route('orders.show', $incident->order) }}">{{ $incident->order->order_id }}</a>
                            </dd>
                            <dt class="col-sm-4 text-muted">Serial Number</dt>
                            <dd class="col-sm-8">{{ $incident->order->serial_number }}</dd>
                            <dt class="col-sm-4 text-muted">Product</dt>
                            <dd class="col-sm-8">{{ $incident->order->product_name }}</dd>
                            <dt class="col-sm-4 text-muted">Device Model</dt>
                            <dd class="col-sm-8">{{ $incident->order->device_model }}</dd>
                            <dt class="col-sm-4 text-muted">Customer</dt>
                            <dd class="col-sm-8">{{ $incident->order->customer_name ?: '—' }}</dd>
                        </dl>
                    @else
                        <p class="text-muted mb-0">No related order found.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Approval Numbers</h2>
                </div>
                <div class="card-body p-0">
                    @if($incident->approvalNumbers->isEmpty())
                        <div class="p-3 text-muted">No approval numbers linked.</div>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach($incident->approvalNumbers as $approval)
                                <li class="list-group-item">
                                    <div class="fw-semibold">{{ $approval->approval_number }}</div>
                                    @if($approval->description)
                                        <div class="small text-muted">{{ $approval->description }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Refund Requests</h2>
                </div>
                <div class="card-body p-0">
                    @if($incident->refundRequests->isEmpty())
                        <div class="p-3 text-muted">No refund requests linked.</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($incident->refundRequests as $refund)
                                        <tr>
                                            <td>
                                                <a href="{{ route('refunds.show', $refund) }}" class="text-decoration-none">
                                                    {{ $refund->reference_no }}
                                                </a>
                                            </td>
                                            <td><span class="badge text-bg-secondary">{{ $refund->status->label() }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            @include('remarks.partials.panel', [
                'remarkable' => $incident,
                'timelineRemarks' => $timelineRemarks,
                'showContextBadge' => false,
            ])
        </div>
    </div>
@endsection
