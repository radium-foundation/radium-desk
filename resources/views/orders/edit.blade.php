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

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('orders.update', $order) }}">
                @csrf
                @method('PUT')

                @include('orders.partials.form', ['order' => $order, 'showStatus' => true])

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="{{ route('orders.show', $order) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
