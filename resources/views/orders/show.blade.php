@extends('layouts.app')

@section('title', $order->order_id)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $order->order_id }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">{{ $order->order_id }}</h1>
            <p class="text-muted mb-0">Order detail and related activity summary.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @include('orders.partials.completion-status-badge', ['order' => $order])
            @can('update', $order)
                <a href="{{ route('orders.edit', $order) }}" class="btn btn-outline-primary">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
            @endcan
            @can('delete', $order)
                <form method="POST" action="{{ route('orders.destroy', $order) }}"
                      onsubmit="return confirm('Are you sure you want to delete this order?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger">
                        <i class="bi bi-trash me-1"></i> Delete
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @if($order->isTransactionLocked())
        <div class="alert alert-success py-2 small mb-3">
            <i class="bi bi-lock-fill me-1"></i>
            This order is completed. Transaction ID <strong>{{ $order->transaction_id }}</strong>
            was saved on {{ display_app_datetime($order->completed_at) }}.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">{{ config('ui.service_case.plural') }}</div>
                    <div class="fs-3 fw-semibold">{{ $order->incidents_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Refund Requests</div>
                    <div class="fs-3 fw-semibold">{{ $order->refund_requests_count }}</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Order Status</div>
                    <div class="mt-1">
                        <span @class([
                            'badge fs-6',
                            'text-bg-success' => $order->status === \App\Enums\OrderStatus::Active,
                            'text-bg-secondary' => $order->status === \App\Enums\OrderStatus::Closed,
                        ])>
                            {{ $order->status->label() }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            @include('orders.partials.activity-timeline', ['activityTimeline' => $activityTimeline])
            @include('orders.partials.update-transaction-form', ['order' => $order])
            @include('orders.partials.service-cases-list', ['order' => $order])

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Order Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Order ID</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $order->order_id }}</dd>

                        <dt class="col-sm-4 text-muted">Serial Number</dt>
                        <dd class="col-sm-8">{{ $order->serial_number }}</dd>

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
