@extends('layouts.app')

@section('title', 'New adjustment')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">New adjustment</h1>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'adjustments'])

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <form method="POST" action="{{ route('inventory.adjustments.store') }}">
                @csrf
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Mode</label>
                        <select name="mode" class="form-select">
                            <option value="serial">Serial status</option>
                            <option value="quantity">Quantity delta</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Reason</label>
                        <select name="reason" class="form-select" required>
                            @foreach($reasons as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Serial</label>
                        <input type="text" name="serial_number" class="form-control" value="{{ old('serial_number') }}">
                        @error('serial_number')<div class="text-danger small">{{ $message }}</div>@enderror
                        @error('serials')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New serial status</label>
                        <select name="to_status" class="form-select">
                            @foreach($statuses as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>
                        @error('status')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Branch (qty mode)</label>
                        <select name="branch_id" class="form-select">
                            <option value="">Select</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Product (qty mode)</label>
                        <select name="product_id" class="form-select">
                            <option value="">Select</option>
                            @foreach($products as $product)
                                <option value="{{ $product->id }}">{{ $product->sku }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Qty delta (+/−)</label>
                        <input type="number" name="qty_delta" class="form-control" value="{{ old('qty_delta') }}">
                        @error('qty')<div class="text-danger small">{{ $message }}</div>@enderror
                        @error('qty_delta')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>
                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">Record adjustment</button>
                    <a href="{{ route('inventory.adjustments.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
