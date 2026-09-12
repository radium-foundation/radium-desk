@extends('layouts.app')

@section('title', 'Goods Receipts')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">Goods Receipts</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'goods_receipts'])

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>PO</th>
                        <th>Vendor</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receipts as $receipt)
                        <tr>
                            <td>{{ $receipt->receipt_number }}</td>
                            <td>{{ $receipt->purchaseOrder->po_number }}</td>
                            <td>{{ $receipt->vendor->business_name }}</td>
                            <td>{{ $receipt->receipt_date->format('Y-m-d') }}</td>
                            <td>{{ $receipt->status->label() }}</td>
                            <td><a href="{{ route('purchasing.goods-receipts.show', $receipt) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted p-4">No goods receipts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $receipts->links() }}</div>
@endsection
