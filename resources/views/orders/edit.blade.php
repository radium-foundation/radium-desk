@extends('layouts.app')

@section('title', 'Edit Order')

@section('content')
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="{{ route('orders.index') }}">Orders</a></li>
                <li class="breadcrumb-item"><a href="{{ route('orders.show', $order) }}">{{ $order->order_id }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Edit</li>
            </ol>
        </nav>
        <h1 class="h3 mb-1">Edit Order</h1>
        <p class="text-muted mb-0">Update order details for {{ $order->order_id }}.</p>
    </div>

    @if($order->isTransactionLocked())
        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <span class="fw-semibold">Completed Order</span>
            <span class="d-block">
                This order has already been completed.
                Any changes made by a Super Admin will be permanently recorded in the Audit Log.
            </span>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('orders.update', $order) }}">
                @csrf
                @method('PUT')

                @include('orders.partials.form', ['order' => $order, 'showStatus' => true, 'deviceModels' => $deviceModels ?? []])

                @if($order->isTransactionLocked())
                    <div class="mt-4">
                        <label for="correction_reason" class="form-label">Reason for correction <span class="text-danger">*</span></label>
                        <textarea name="correction_reason" id="correction_reason" rows="3"
                                  class="form-control @error('correction_reason') is-invalid @enderror"
                                  required>{{ old('correction_reason') }}</textarea>
                        @error('correction_reason')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Required for all changes to completed orders.</div>
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
