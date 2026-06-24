@extends('layouts.app')

@section('title', $approval->approval_number)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('approvals.index') }}">Approvals</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $approval->approval_number }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">{{ $approval->approval_number }}</h1>
            <p class="text-muted mb-0">Approval number detail</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @include('approvals.partials.incident-counter', ['count' => $approval->incidents_count])
            @can('delete', $approval)
                <form method="POST" action="{{ route('approvals.destroy', $approval) }}"
                      onsubmit="return confirm('Are you sure you want to delete this approval number?');">
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
                    <h2 class="h6 mb-0">Approval Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Approval Number</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $approval->approval_number }}</dd>

                        <dt class="col-sm-4 text-muted">Created By</dt>
                        <dd class="col-sm-8">{{ $approval->creator?->name ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Created Date</dt>
                        <dd class="col-sm-8">{{ $approval->created_at?->format('d M Y, H:i') ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Description</dt>
                        <dd class="col-sm-8">{{ $approval->description ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Linked {{ config('ui.service_case.plural') }}</dt>
                        <dd class="col-sm-8">
                            @include('approvals.partials.incident-counter', ['count' => $approval->incidents_count])
                            @if($approval->incidents_count >= \App\Models\ApprovalNumber::MAX_INCIDENTS)
                                <span class="badge text-bg-danger ms-1">Limit reached</span>
                            @endif
                        </dd>
                    </dl>

                    @php
                        $progressPercent = min(
                            100,
                            round(($approval->incidents_count / \App\Models\ApprovalNumber::MAX_INCIDENTS) * 100),
                        );
                        $progressClass = match (true) {
                            $approval->incidents_count >= \App\Models\ApprovalNumber::MAX_INCIDENTS => 'bg-danger',
                            $approval->incidents_count >= (\App\Models\ApprovalNumber::MAX_INCIDENTS - 5) => 'bg-warning',
                            default => 'bg-primary',
                        };
                    @endphp
                    <div class="progress mt-3" style="height: 0.5rem;" aria-hidden="true">
                        <div class="progress-bar {{ $progressClass }}" style="width: {{ $progressPercent }}%;"></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">Linked {{ config('ui.service_case.plural') }}</h2>
                    <span class="text-muted small">{{ $approval->incidents_count }} service case(s)</span>
                </div>
                @if($approval->incidents->isEmpty())
                    <div class="card-body text-muted">{{ config('ui.service_case.linked_empty') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Reference</th>
                                    <th>Order</th>
                                    <th>Title</th>
                                    <th>Status</th>
                                    @can('link', $approval)
                                        <th class="text-end">Actions</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($approval->incidents as $incident)
                                    <tr>
                                        <td class="fw-semibold">
                                            <a href="{{ route('incidents.show', $incident) }}" class="text-decoration-none">
                                                {{ $incident->reference_no }}
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
                                        <td>{{ $incident->title }}</td>
                                        <td>
                                            <span class="badge text-bg-secondary">{{ $incident->status->label() }}</span>
                                        </td>
                                        @can('link', $approval)
                                            <td class="text-end">
                                                <form method="POST"
                                                      action="{{ route('approvals.incidents.unlink', [$approval, $incident]) }}"
                                                      onsubmit="return confirm('Remove this service case from the approval number?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Unlink">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        @endcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Linked Orders</h2>
                </div>
                @if($linkedOrders->isEmpty())
                    <div class="card-body text-muted">No orders linked yet. Orders are derived from linked service cases.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach($linkedOrders as $order)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="{{ route('orders.show', $order) }}" class="fw-semibold text-decoration-none">
                                        {{ $order->order_id }}
                                    </a>
                                    <div class="small text-muted">
                                        {{ $order->serial_number }} · {{ $order->product_name }}
                                    </div>
                                </div>
                                <span class="badge text-bg-light text-dark border">
                                    {{ $approval->incidents->where('order_id', $order->id)->count() }}
                                    service case(s)
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="col-lg-5">
            @can('link', $approval)
                @if($remainingSlots > 0)
                    @include('approvals.partials.link-incidents-form', [
                        'approval' => $approval,
                        'remainingSlots' => $remainingSlots,
                    ])
                @else
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                This approval number has reached the maximum of
                                {{ \App\Models\ApprovalNumber::MAX_INCIDENTS }} linked service cases.
                            </div>
                        </div>
                    </div>
                @endif
            @endcan
        </div>
    </div>
@endsection
