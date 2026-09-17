@extends('layouts.app')

@section('title', 'Receive goods')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Receive against {{ $purchaseOrder->po_number }}</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'goods_receipts'])

    <form method="POST" action="{{ route('purchasing.goods-receipts.store', $purchaseOrder) }}" class="card border-0 shadow-sm p-4">
        @csrf
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label">Receipt date</label>
                <input type="date" name="receipt_date" class="form-control" value="{{ now()->toDateString() }}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Supplier challan reference</label>
                <input type="text" name="supplier_challan_reference" class="form-control">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Remaining</th>
                        <th>Qty received</th>
                        <th>Serials (one per line or paste)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($purchaseOrder->items as $index => $item)
                        <tr>
                            <td>
                                {{ $item->sku }}
                                <input type="hidden" name="lines[{{ $index }}][purchase_order_item_id]" value="{{ $item->id }}">
                                <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $item->product_id }}">
                            </td>
                            <td>{{ $item->quantityRemaining() }}</td>
                            <td><input type="number" name="lines[{{ $index }}][quantity_received]" class="form-control" min="0" max="{{ $item->quantityRemaining() }}" value="0"></td>
                            <td>
                                @if($item->product->is_serialized)
                                    <textarea name="serials[{{ $index }}]" class="form-control" rows="3" placeholder="Scan or paste serials"></textarea>
                                @else
                                    <span class="text-muted small">Quantity only</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button class="btn btn-primary">Submit for confirmation</button>
    </form>
@endsection
