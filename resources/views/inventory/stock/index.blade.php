@extends('layouts.app')

@section('title', 'Stock')

@section('content')
    <div class="mb-4 d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
            <h1 class="h3 mb-1">Stock by branch</h1>
            <p class="text-muted mb-0">Available and reserved quantities. Serialised products also appear in Serials.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($canStockIn)
                <a href="{{ route('inventory.stock.create') }}" class="btn btn-primary">Stock in</a>
            @endif
            @if($canReserve)
                <a href="{{ route('inventory.reservations.create') }}" class="btn btn-outline-secondary">Reserve</a>
            @endif
            @if($canAdjust)
                <a href="{{ route('inventory.adjustments.create') }}" class="btn btn-outline-secondary">Adjust</a>
            @endif
        </div>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'stock'])

    @include('inventory.partials.branch-scope-empty')

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-4">
            <select name="branch_id" class="form-select">
                <option value="">All branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <select name="product_id" class="form-select">
                <option value="">All products</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected((string) ($filters['product_id'] ?? '') === (string) $product->id)>{{ $product->sku }} — {{ $product->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-outline-secondary">Filter</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Variant</th>
                        <th>Branch</th>
                        <th class="text-end">Available</th>
                        <th class="text-end">Reserved</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($balances as $balance)
                        <tr>
                            <td>{{ $balance->product?->sku }} — {{ $balance->product?->name }}</td>
                            <td>{{ $balance->variant?->name ?? '—' }}</td>
                            <td>{{ $balance->branch?->name }}</td>
                            <td class="text-end">{{ $balance->available_qty }}</td>
                            <td class="text-end">{{ $balance->reserved_qty }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-muted p-4">{{ !empty($needsBranchAssignment) ? 'No branch assignment — stock for other locations is hidden.' : 'No stock recorded yet. Receive stock in to start.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $balances->links() }}</div>
@endsection
