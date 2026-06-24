@extends('layouts.app')

@section('title', $refund->reference_no)

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="{{ route('refunds.index') }}">Refunds</a></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ $refund->reference_no }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">{{ $refund->reference_no }}</h1>
            <p class="text-muted mb-0">Refund request detail</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            @include('refunds.partials.status-badge', ['status' => $refund->status])
            @can('delete', $refund)
                <form method="POST" action="{{ route('refunds.destroy', $refund) }}"
                      onsubmit="return confirm('Are you sure you want to delete this refund request?');">
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
                    <h2 class="h6 mb-0">Refund Information</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Reference</dt>
                        <dd class="col-sm-8 fw-semibold">{{ $refund->reference_no }}</dd>

                        <dt class="col-sm-4 text-muted">Status</dt>
                        <dd class="col-sm-8">@include('refunds.partials.status-badge', ['status' => $refund->status])</dd>

                        <dt class="col-sm-4 text-muted">Amount</dt>
                        <dd class="col-sm-8">{{ number_format($refund->amount, 2) }}</dd>

                        <dt class="col-sm-4 text-muted">Reason</dt>
                        <dd class="col-sm-8">{!! nl2br(e($refund->reason)) !!}</dd>

                        <dt class="col-sm-4 text-muted">Requested By</dt>
                        <dd class="col-sm-8">{{ $refund->requester?->name ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Requested Date</dt>
                        <dd class="col-sm-8">{{ $refund->created_at?->format('d M Y, H:i') ?: '—' }}</dd>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Related Order</h2>
                </div>
                <div class="card-body">
                    @if($refund->order)
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Order ID</dt>
                            <dd class="col-sm-8">
                                <a href="{{ route('orders.show', $refund->order) }}">{{ $refund->order->order_id }}</a>
                            </dd>

                            <dt class="col-sm-4 text-muted">Serial Number</dt>
                            <dd class="col-sm-8">{{ $refund->order->serial_number }}</dd>

                            <dt class="col-sm-4 text-muted">Product</dt>
                            <dd class="col-sm-8">{{ $refund->order->product_name }} ({{ $refund->order->device_model }})</dd>

                            <dt class="col-sm-4 text-muted">Customer</dt>
                            <dd class="col-sm-8">{{ $refund->order->customer_name ?: '—' }}</dd>
                        </dl>
                    @else
                        <p class="text-muted mb-0">No related order.</p>
                    @endif
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Related {{ config('ui.service_case.singular') }}</h2>
                </div>
                <div class="card-body">
                    @if($refund->incident)
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Reference</dt>
                            <dd class="col-sm-8">
                                <a href="{{ route('incidents.show', $refund->incident) }}">{{ $refund->incident->reference_no }}</a>
                            </dd>

                            <dt class="col-sm-4 text-muted">Title</dt>
                            <dd class="col-sm-8">{{ $refund->incident->title }}</dd>

                            <dt class="col-sm-4 text-muted">Status</dt>
                            <dd class="col-sm-8">
                                <span class="badge text-bg-secondary">{{ $refund->incident->status->label() }}</span>
                            </dd>
                        </dl>
                    @else
                        <p class="text-muted mb-0">No related service case.</p>
                    @endif
                </div>
            </div>

            @if($refund->reviewed_at)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Review History</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Decision</dt>
                            <dd class="col-sm-8">@include('refunds.partials.status-badge', ['status' => $refund->status])</dd>

                            <dt class="col-sm-4 text-muted">Reviewed By</dt>
                            <dd class="col-sm-8">{{ $refund->reviewer?->name ?? '—' }}</dd>

                            <dt class="col-sm-4 text-muted">Reviewed Date</dt>
                            <dd class="col-sm-8">{{ $refund->reviewed_at?->format('d M Y, H:i') ?: '—' }}</dd>

                            @if($refund->refund_transaction_id)
                                <dt class="col-sm-4 text-muted">Refund Transaction ID</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $refund->refund_transaction_id }}</dd>
                            @endif

                            <dt class="col-sm-4 text-muted">Review Notes</dt>
                            <dd class="col-sm-8">{!! nl2br(e($refund->review_notes ?: '—')) !!}</dd>
                        </dl>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-5">
            @can('review', $refund)
                @if($refund->status === \App\Enums\RefundStatus::Pending)
                    @include('refunds.partials.review-panel')
                @endif
            @endcan

            @include('remarks.partials.panel', [
                'remarkable' => $refund,
                'timelineRemarks' => $timelineRemarks,
                'showContextBadge' => false,
            ])
        </div>
    </div>
@endsection
