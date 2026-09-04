@extends('layouts.app')

@section('title', 'Products')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Products / SKUs</h1>
        </div>
        <a href="{{ route('inventory.products.create') }}" class="btn btn-primary">New product</a>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'products'])

    <form method="GET" class="mb-3">
        <div class="input-group" style="max-width: 28rem;">
            <input type="text" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="SKU or name">
            <button class="btn btn-outline-secondary">Search</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Name</th>
                        <th>GST %</th>
                        <th>Price</th>
                        <th>Tracking</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>{{ $product->sku }}</td>
                            <td>{{ $product->name }}</td>
                            <td>{{ $product->gst_percentage }}</td>
                            <td>{{ $product->unit_price }}</td>
                            <td>{{ $product->is_serialized ? 'Serial' : 'Quantity' }}</td>
                            <td><a href="{{ route('inventory.products.edit', $product) }}">Edit</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted p-4">No products yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $products->links() }}</div>
@endsection
