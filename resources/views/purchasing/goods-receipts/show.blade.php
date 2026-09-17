@extends('layouts.app')

@section('title', $goodsReceipt->receipt_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">{{ $goodsReceipt->receipt_number }}</h1>
            <p class="text-muted mb-0">{{ $goodsReceipt->purchaseOrder->po_number }} · {{ $goodsReceipt->status->label() }}</p>
        </div>
        @if($goodsReceipt->status->value === 'pending_confirmation')
            @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE)
                <form method="POST" action="{{ route('purchasing.goods-receipts.complete', $goodsReceipt) }}">
                    @csrf
                    <button class="btn btn-primary">Complete receiving</button>
                </form>
            @endcan
        @endif
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'goods_receipts'])

    <div class="card border-0 shadow-sm p-4 mb-3">
        <h2 class="h5">Reconciliation summary</h2>
        <dl class="row mb-0">
            <dt class="col-sm-4">Ordered (PO)</dt><dd class="col-sm-8">{{ $summary['ordered'] }}</dd>
            <dt class="col-sm-4">Received (this receipt)</dt><dd class="col-sm-8">{{ $summary['received'] }}</dd>
            <dt class="col-sm-4">Serials captured</dt><dd class="col-sm-8">{{ $summary['serial_count'] }}</dd>
            <dt class="col-sm-4">Invalid serials</dt><dd class="col-sm-8">{{ $summary['invalid_serials'] }}</dd>
            <dt class="col-sm-4">Duplicate serials</dt><dd class="col-sm-8">{{ $summary['duplicate_serials'] }}</dd>
            <dt class="col-sm-4">Supplier invoice</dt><dd class="col-sm-8">{{ $summary['supplier_invoice_present'] ? 'Present' : 'Not recorded' }}</dd>
            <dt class="col-sm-4">Payment status</dt><dd class="col-sm-8">{{ $summary['payment_status'] ?? '—' }}</dd>
        </dl>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold">Lines & serials</div>
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Received</th>
                        <th>Serials</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($goodsReceipt->items as $item)
                        <tr>
                            <td>{{ $item->product->sku }}</td>
                            <td>{{ $item->quantity_received }}</td>
                            <td>
                                @if($item->product->is_serialized)
                                    @foreach($item->serials as $serial)
                                        <div>
                                            <code>{{ $serial->serial_number }}</code>
                                            <span class="text-muted small">({{ $serial->validation_status->value }})</span>
                                        </div>
                                    @endforeach
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
