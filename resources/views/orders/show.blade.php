@extends('layouts.app')

@section('title', $order->order_id)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $order->order_id }}</li>
                </ol>
            </nav>
            <p class="text-muted mb-0">Order hub and service case history.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @can('update', $order)
                <a href="{{ route('orders.edit', $order) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
            @endcan
            @can('delete', $order)
                <form method="POST" action="{{ route('orders.destroy', $order) }}"
                      onsubmit="return confirm('Are you sure you want to delete this order?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @include('orders.partials.order-summary', [
        'order' => $order,
        'activeIncident' => $activeIncident ?? null,
    ])

    @if($order->isTransactionLocked())
        <div class="alert alert-success py-2 small mb-3">
            <i class="bi bi-lock-fill me-1"></i>
            This order is completed. Transaction ID <strong>{{ $order->transaction_id }}</strong>
            was saved on {{ display_app_datetime($order->completed_at) }}.
        </div>
    @endif

    @include('orders.partials.active-service-case-banner', [
        'order' => $order,
        'activeIncident' => $activeIncident ?? null,
    ])

    @include('orders.partials.service-cases-list', ['order' => $order])

    <div class="row g-3">
        <div class="col-lg-7">
            @include('orders.partials.activity-timeline', ['activityTimeline' => $activityTimeline])
            @include('orders.partials.update-transaction-form', ['order' => $order])

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Order Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Order ID</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $order->order_id }}</dd>

                        <dt class="col-sm-4 text-muted">Serial Number</dt>
                        <dd class="col-sm-8">
                            @if($order->serial_number)
                                <span class="font-monospace">{{ $order->serial_number }}</span>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">Entered By</dt>
                        <dd class="col-sm-8">{{ $order->serialEnterer?->name ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Entered At</dt>
                        <dd class="col-sm-8">{{ $order->serial_entered_at ? display_app_datetime($order->serial_entered_at) : '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Product Name</dt>
                        <dd class="col-sm-8">{{ $order->product_name }}</dd>

                        <dt class="col-sm-4 text-muted">Device Model</dt>
                        <dd class="col-sm-8">{{ $order->device_model }}</dd>

                        <dt class="col-sm-4 text-muted">Transaction ID</dt>
                        <dd class="col-sm-8">{{ $order->transaction_id ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Order Completion Status</dt>
                        <dd class="col-sm-8">@include('orders.partials.completion-status-badge', ['order' => $order])</dd>

                        <dt class="col-sm-4 text-muted">Customer Name</dt>
                        <dd class="col-sm-8">{{ $order->customer_name ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Customer Email</dt>
                        <dd class="col-sm-8">
                            @if($order->customer_email)
                                <a href="mailto:{{ $order->customer_email }}">{{ $order->customer_email }}</a>
                            @else
                                —
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">Customer Phone</dt>
                        <dd class="col-sm-8">{{ $order->customer_phone ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Created</dt>
                        <dd class="col-sm-8">{{ display_app_datetime_24($order->created_at) }}</dd>

                        <dt class="col-sm-4 text-muted">Last Updated</dt>
                        <dd class="col-sm-8">{{ display_app_datetime_24($order->updated_at) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            @include('remarks.partials.panel', [
                'remarkable' => $order,
                'timelineRemarks' => $timelineRemarks,
                'showContextBadge' => true,
            ])
        </div>
    </div>
@endsection
