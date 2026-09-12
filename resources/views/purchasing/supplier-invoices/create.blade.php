@extends('layouts.app')

@section('title', 'Record supplier invoice')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Supplier invoice for {{ $purchaseOrder->po_number }}</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'supplier_invoices'])

    <form method="POST" action="{{ route('purchasing.supplier-invoices.store', $purchaseOrder) }}" enctype="multipart/form-data" class="card border-0 shadow-sm p-4">
        @csrf
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Supplier invoice number</label>
                <input type="text" name="supplier_invoice_number" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Invoice date</label>
                <input type="date" name="invoice_date" class="form-control" value="{{ now()->toDateString() }}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Invoice amount</label>
                <input type="number" step="0.01" name="invoice_amount" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Goods receipt (optional)</label>
                <select name="goods_receipt_id" class="form-select">
                    <option value="">—</option>
                    @foreach($receipts as $receipt)
                        <option value="{{ $receipt->id }}">{{ $receipt->receipt_number }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Taxable amount</label>
                <input type="number" step="0.01" name="taxable_amount" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Upload document</label>
                <input type="file" name="document" class="form-control">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"></textarea>
            </div>
        </div>
        <button class="btn btn-primary mt-3">Record invoice</button>
    </form>
@endsection
