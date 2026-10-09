@extends('layouts.app')

@section('title', 'New inter-branch transfer')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">New inter-branch transfer</h1>
        <p class="text-muted mb-0">This creates a linked GST invoice and inventory transfer. It is <strong>not</strong> a retail customer sale.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'inter-branch'])

    @include('inventory.partials.branch-scope-empty')

    <div class="alert alert-warning">
        Inter-branch stock must not be sold through POS at the source branch. Final customer sales happen at the receiving branch after receipt.
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.inter-branch-transfers.store') }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">From branch</label>
                        <select name="from_branch_id" class="form-select" required>
                            <option value="">Select</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('from_branch_id') == $branch->id)>
                                    {{ $branch->code }} — {{ $branch->name }} @if($branch->gstin)({{ $branch->gstin }})@endif
                                </option>
                            @endforeach
                        </select>
                        @error('from_branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">To branch</label>
                        <select name="to_branch_id" class="form-select" required>
                            <option value="">Select</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('to_branch_id') == $branch->id)>
                                    {{ $branch->code }} — {{ $branch->name }} @if($branch->gstin)({{ $branch->gstin }})@endif
                                </option>
                            @endforeach
                        </select>
                        @error('to_branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Product</label>
                        <select name="lines[0][product_id]" class="form-select" required>
                            <option value="">Select</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}" @selected(old('lines.0.product_id') == $product->id)>
                                    {{ $product->sku }} — {{ $product->name }} ({{ $product->is_serialized ? 'serialised' : 'quantity' }})
                                </option>
                            @endforeach
                        </select>
                        @error('lines.0.product_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Quantity</label>
                        <input type="number" min="1" name="lines[0][qty]" class="form-control" value="{{ old('lines.0.qty', 1) }}" required>
                        @error('lines.0.qty')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label">Serial numbers (one per line, for serialised products)</label>
                        <textarea name="lines[0][serials]" class="form-control" rows="4" placeholder="Enter serial numbers separated by commas or new lines">{{ old('lines.0.serials') }}</textarea>
                        @error('lines.0.serials')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Confirm inter-branch issue</button>
                    <a href="{{ route('inventory.inter-branch-transfers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
