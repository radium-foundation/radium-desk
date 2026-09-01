@extends('layouts.app')

@section('title', 'New transfer')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">New transfer</h1>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'transfers'])

    @include('inventory.partials.branch-scope-empty')

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.transfers.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">From branch</label>
                        <select name="from_branch_id" class="form-select" required>
                            <option value="">Select</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('from_branch_id') == $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('from_branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">To branch</label>
                        <select name="to_branch_id" class="form-select" required>
                            <option value="">Select</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('to_branch_id') == $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('to_branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Mode</label>
                        <select name="mode" class="form-select">
                            <option value="serial" @selected(old('mode', 'serial') === 'serial')>Serials</option>
                            <option value="quantity" @selected(old('mode') === 'quantity')>Quantity (non-serialised)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Serials</label>
                        <textarea name="serials" class="form-control" rows="4">{{ old('serials') }}</textarea>
                        @error('serials')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Product (quantity mode)</label>
                        <select name="product_id" class="form-select">
                            <option value="">Select</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->sku }} — {{ $product->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Qty</label>
                        <input type="number" min="1" name="qty" class="form-control" value="{{ old('qty') }}">
                        @error('qty')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Complete transfer</button>
                    <a href="{{ route('inventory.transfers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
