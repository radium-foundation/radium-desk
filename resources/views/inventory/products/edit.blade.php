@extends('layouts.app')

@section('title', 'Edit '.$product->sku)

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Edit {{ $product->sku }}</h1>
    </div>
    @include('inventory.partials.workspace-nav', ['active' => 'products'])
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.products.update', $product) }}">
                @csrf
                @method('PUT')
                @include('inventory.products.partials.form')
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Save</button>
                    <a href="{{ route('inventory.products.index') }}" class="btn btn-outline-secondary">Back</a>
                </div>
            </form>
        </div>
    </div>
@endsection
