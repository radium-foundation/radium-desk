@extends('layouts.app')

@section('title', $purchaseOrder->po_number)

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">{{ $purchaseOrder->po_number }}</h1>
            <p class="text-muted mb-0">{{ $purchaseOrder->vendor->business_name }} · {{ $purchaseOrder->status->label() }}</p>
        </div>
        <div class="d-flex gap-2">
            @if($purchaseOrder->status->value === 'draft')
                @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_EDIT)
                    <form method="POST" action="{{ route('purchasing.purchase-orders.send', $purchaseOrder) }}">@csrf<button class="btn btn-primary">Send PO</button></form>
                @endcan
            @endif
            @if($purchaseOrder->status->canReceive())
                @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE)
                    <a href="{{ route('purchasing.goods-receipts.create', $purchaseOrder) }}" class="btn btn-outline-primary">Receive goods</a>
                @endcan
            @endif
            @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE)
                <a href="{{ route('purchasing.supplier-invoices.create', $purchaseOrder) }}" class="btn btn-outline-secondary">Record supplier invoice</a>
            @endcan
        </div>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><span class="nav-link active">PO Details</span></li>
        <li class="nav-item"><span class="nav-link disabled">Products</span></li>
        <li class="nav-item"><span class="nav-link disabled">Receiving</span></li>
        <li class="nav-item"><span class="nav-link disabled">Payments</span></li>
        <li class="nav-item"><span class="nav-link disabled">Activity</span></li>
    </ul>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Products</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Ordered</th>
                                <th>Received</th>
                                <th>Unit cost</th>
                                <th class="text-end">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($purchaseOrder->items as $item)
                                <tr>
                                    <td>{{ $item->sku }}</td>
                                    <td>{{ $item->quantity_ordered }}</td>
                                    <td>{{ $item->quantity_received }}</td>
                                    <td>₹{{ number_format((float) $item->unit_cost, 2) }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Goods receipts</div>
                <div class="list-group list-group-flush">
                    @forelse($purchaseOrder->goodsReceipts as $receipt)
                        <a href="{{ route('purchasing.goods-receipts.show', $receipt) }}" class="list-group-item list-group-item-action">
                            {{ $receipt->receipt_number }} · {{ $receipt->status->label() }}
                        </a>
                    @empty
                        <div class="list-group-item text-muted">No receipts yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3 p-3">
                <div class="d-flex justify-content-between"><span>Subtotal</span><span>₹{{ number_format((float) $purchaseOrder->subtotal, 2) }}</span></div>
                <div class="d-flex justify-content-between"><span>Tax</span><span>₹{{ number_format((float) $purchaseOrder->tax_total, 2) }}</span></div>
                <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-2"><span>Grand total</span><span>₹{{ number_format((float) $purchaseOrder->grand_total, 2) }}</span></div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Activity</div>
                <ul class="list-group list-group-flush small">
                    @forelse($audit as $entry)
                        <li class="list-group-item">{{ $entry->event }} · {{ $entry->created_at?->format('Y-m-d H:i') }}</li>
                    @empty
                        <li class="list-group-item text-muted">No audit entries.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
