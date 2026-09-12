@extends('layouts.app')

@section('title', 'New purchase order')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">New purchase order</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <form method="POST" action="{{ route('purchasing.purchase-orders.store') }}" class="card border-0 shadow-sm p-4">
        @csrf
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select" required>
                    @foreach($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Receiving branch</label>
                <select name="branch_id" class="form-select" required>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">PO date</label>
                <input type="date" name="po_date" class="form-control" value="{{ now()->toDateString() }}" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Expected delivery</label>
                <input type="date" name="expected_delivery_date" class="form-control">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
        </div>

        <h2 class="h5">Products</h2>
        <div class="table-responsive mb-3">
            <table class="table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Unit cost</th>
                        <th>Tax %</th>
                        <th>Discount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products->take(5) as $index => $product)
                        <tr>
                            <td>
                                <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $product->id }}">
                                {{ $product->sku }} — {{ $product->name }}
                            </td>
                            <td><input type="number" name="lines[{{ $index }}][quantity]" class="form-control" min="0" value="0"></td>
                            <td><input type="number" step="0.01" name="lines[{{ $index }}][unit_cost]" class="form-control" value="{{ $product->unit_cost ?? 0 }}"></td>
                            <td><input type="number" step="0.01" name="lines[{{ $index }}][tax_rate]" class="form-control" value="{{ $product->gst_percentage ?? 0 }}"></td>
                            <td><input type="number" step="0.01" name="lines[{{ $index }}][discount_amount]" class="form-control" value="0"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted small">Enter quantity &gt; 0 on at least one line before saving.</p>
        <button class="btn btn-primary">Create draft PO</button>
    </form>
@endsection
