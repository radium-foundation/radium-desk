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
            <p class="text-muted mb-0">Refund management detail</p>
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

                        <dt class="col-sm-4 text-muted">Refund Amount</dt>
                        <dd class="col-sm-8">₹{{ number_format($refund->displayAmount(), 2) }}</dd>

                        <dt class="col-sm-4 text-muted">Customer Preference</dt>
                        <dd class="col-sm-8">{{ $refund->customer_preferred_method?->label() ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Approved Method</dt>
                        <dd class="col-sm-8">{{ $refund->approved_refund_method?->label() ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Reason</dt>
                        <dd class="col-sm-8">{!! nl2br(e($refund->reason)) !!}</dd>

                        <dt class="col-sm-4 text-muted">Requested By</dt>
                        <dd class="col-sm-8">{{ $refund->requester?->name ?? '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Requested Date</dt>
                        <dd class="col-sm-8">{{ display_app_datetime_24($refund->created_at) }}</dd>

                        @if($refund->communication_channels)
                            <dt class="col-sm-4 text-muted">Notify Channels</dt>
                            <dd class="col-sm-8">{{ implode(', ', $refund->communication_channels) }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white py-3">
                    <h2 class="h6 mb-0">Calculation Snapshot</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-6 text-muted">Total Paid</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->total_paid_amount ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Already Refunded</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->already_refunded_amount ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Maximum Refundable</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->maximum_refundable ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Cancellation Charges</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->cancellation_charges ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">GST on Cancellation</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->gst_on_cancellation ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Other Deduction</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->other_deduction ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Total Deduction</dt>
                        <dd class="col-sm-6">₹{{ number_format($refund->total_deduction ?? 0, 2) }}</dd>
                        <dt class="col-sm-6 text-muted">Profile</dt>
                        <dd class="col-sm-6">{{ $refund->deduction_profile_key?->label() ?? '—' }}</dd>
                        @if($refund->partial_difference_reason)
                            <dt class="col-sm-6 text-muted">Difference Reason</dt>
                            <dd class="col-sm-6">{{ $refund->partial_difference_reason->label() }}</dd>
                        @endif
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
                                <a href="{{ route('incidents.show', $refund->incident) }}">{{ $refund->incident->display_reference }}</a>
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
                <div class="card border-0 shadow-sm mb-3">
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
                            <dd class="col-sm-8">{{ display_app_datetime_24($refund->reviewed_at) }}</dd>

                            @if($refund->reject_reason || ($refund->status === \App\Enums\RefundStatus::Rejected && $refund->review_notes))
                                <dt class="col-sm-4 text-muted">Reject Reason</dt>
                                <dd class="col-sm-8">{!! nl2br(e($refund->reject_reason ?: $refund->review_notes)) !!}</dd>
                            @else
                                <dt class="col-sm-4 text-muted">Review Notes</dt>
                                <dd class="col-sm-8">{!! nl2br(e($refund->review_notes ?: '—')) !!}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            @endif

            @if($refund->executed_at)
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h2 class="h6 mb-0">Execution History</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Executed By</dt>
                            <dd class="col-sm-8">{{ $refund->executor?->name ?? '—' }}</dd>
                            <dt class="col-sm-4 text-muted">Executed Date</dt>
                            <dd class="col-sm-8">{{ display_app_datetime_24($refund->executed_at) }}</dd>
                            <dt class="col-sm-4 text-muted">Reference Number</dt>
                            <dd class="col-sm-8">{{ $refund->execution_reference_no ?: '—' }}</dd>
                            <dt class="col-sm-4 text-muted">Transaction ID</dt>
                            <dd class="col-sm-8 fw-semibold">{{ $refund->effectiveTransactionId() ?: '—' }}</dd>
                            <dt class="col-sm-4 text-muted">Execution Notes</dt>
                            <dd class="col-sm-8">{!! nl2br(e($refund->execution_remarks ?: '—')) !!}</dd>
                            @if($refund->closed_at)
                                <dt class="col-sm-4 text-muted">Closed At</dt>
                                <dd class="col-sm-8">{{ display_app_datetime_24($refund->closed_at) }}</dd>
                            @endif
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

            @can('execute', $refund)
                @if($refund->status === \App\Enums\RefundStatus::PendingExecution)
                    @include('refunds.partials.execute-panel')
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
