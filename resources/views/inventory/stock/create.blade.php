@extends('layouts.app')

@section('title', 'Stock in')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Stock in</h1>
        <p class="text-muted mb-0">Receive serials or quantity at a branch. Duplicate serials are rejected.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'stock'])

    @include('inventory.partials.branch-scope-empty')

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.stock.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="branch_id">Branch</label>
                        <select name="branch_id" id="branch_id" class="form-select" required>
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="product_id">Product</label>
                        <select name="product_id" id="product_id" class="form-select" required>
                            <option value="">Select product</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" data-serialized="{{ $product->is_serialized ? '1' : '0' }}" @selected(old('product_id') == $product->id)>
                                    {{ $product->sku }} — {{ $product->name }} ({{ $product->is_serialized ? 'serialised' : 'qty' }})
                                </option>
                            @endforeach
                        </select>
                        @error('product_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="qty">Quantity (non-serialised)</label>
                        <input type="number" min="1" name="qty" id="qty" class="form-control" value="{{ old('qty') }}">
                        @error('qty')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="batch_code">Batch / lot</label>
                        <input type="text" name="batch_code" id="batch_code" class="form-control" value="{{ old('batch_code') }}">
                        @error('batch_code')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="serials">Serial numbers</label>
                        <textarea name="serials" id="serials" class="form-control" rows="5" placeholder="One per line, or comma-separated">{{ old('serials') }}</textarea>
                        @error('serials')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <input type="text" name="notes" id="notes" class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Receive stock</button>
                    <a href="{{ route('inventory.stock.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
