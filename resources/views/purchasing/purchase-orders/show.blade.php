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
                    <form method="POST" action="{{ route('purchasing.purchase-orders.send', $purchaseOrder) }}">@csrf<button class="btn btn-primary">Release PO</button></form>
                @endcan
            @endif
            @if($purchaseOrder->status->canReceive())
                @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_RECEIVE)
                    <a href="{{ route('purchasing.goods-receipts.create', $purchaseOrder) }}" class="btn btn-outline-primary">Receive goods</a>
                @endcan
            @endif
            @if(! in_array($purchaseOrder->status->value, ['draft', 'cancelled'], true))
                @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE)
                    <a href="{{ route('purchasing.supplier-invoices.create', $purchaseOrder) }}" class="btn btn-outline-secondary">Record supplier invoice</a>
                @endcan
            @endif
        </div>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    @php
        $activeTab = $activeTab ?? 'details';
        $tabs = [
            'details' => 'PO Details',
            'products' => 'Products',
            'receiving' => 'Receiving',
            'payments' => 'Payments',
            'activity' => 'Activity',
        ];
    @endphp

    <ul class="nav nav-pills mb-3" id="po-detail-tabs" role="tablist">
        @foreach($tabs as $tabKey => $tabLabel)
            <li class="nav-item" role="presentation">
                <button
                    class="nav-link{{ $activeTab === $tabKey ? ' active' : '' }}"
                    id="po-tab-{{ $tabKey }}"
                    data-bs-toggle="tab"
                    data-bs-target="#po-pane-{{ $tabKey }}"
                    type="button"
                    role="tab"
                    aria-controls="po-pane-{{ $tabKey }}"
                    aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}"
                >
                    {{ $tabLabel }}
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content" id="po-detail-tab-content">
        <div
            class="tab-pane fade{{ $activeTab === 'details' ? ' show active' : '' }}"
            id="po-pane-details"
            role="tabpanel"
            aria-labelledby="po-tab-details"
            tabindex="0"
        >
            @if(! $purchaseOrder->status->canEdit())
                <div class="alert alert-light border small mb-3">
                    This purchase order has been released and can no longer be edited. Record supplier invoices and payments on the Payments tab; inventory changes flow through goods receipts.
                </div>
            @endif

            <div class="card border-0 shadow-sm p-4 mb-3">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Vendor</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->vendor->business_name }}</dd>
                    <dt class="col-sm-3">Receiving branch</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->branch->name }}</dd>
                    <dt class="col-sm-3">PO date</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->po_date->format('Y-m-d') }}</dd>
                    <dt class="col-sm-3">Expected delivery</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->expected_delivery_date?->format('Y-m-d') ?? '—' }}</dd>
                    <dt class="col-sm-3">Status</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->status->label() }}</dd>
                    <dt class="col-sm-3">Released at</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->sent_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                    <dt class="col-sm-3">Notes</dt>
                    <dd class="col-sm-9">{{ $purchaseOrder->notes ?? '—' }}</dd>
                </dl>
            </div>

            <div class="card border-0 shadow-sm p-3">
                <div class="d-flex justify-content-between"><span>Subtotal</span><span>₹{{ number_format((float) $purchaseOrder->subtotal, 2) }}</span></div>
                <div class="d-flex justify-content-between"><span>Tax</span><span>₹{{ number_format((float) $purchaseOrder->tax_total, 2) }}</span></div>
                @if((float) $purchaseOrder->discount_total > 0)
                    <div class="d-flex justify-content-between"><span>Discount</span><span>₹{{ number_format((float) $purchaseOrder->discount_total, 2) }}</span></div>
                @endif
                <div class="d-flex justify-content-between fw-semibold border-top pt-2 mt-2"><span>Grand total</span><span>₹{{ number_format((float) $purchaseOrder->grand_total, 2) }}</span></div>
            </div>
        </div>

        <div
            class="tab-pane fade{{ $activeTab === 'products' ? ' show active' : '' }}"
            id="po-pane-products"
            role="tabpanel"
            aria-labelledby="po-tab-products"
            tabindex="0"
        >
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Products</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th>Ordered</th>
                                <th>Received</th>
                                <th>Unit cost</th>
                                <th class="text-end">Line total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($purchaseOrder->items as $item)
                                <tr>
                                    <td>{{ $item->sku }}</td>
                                    <td>{{ $item->product->name }}</td>
                                    <td>{{ $item->quantity_ordered }}</td>
                                    <td>{{ $item->quantity_received }}</td>
                                    <td>₹{{ number_format((float) $item->unit_cost, 2) }}</td>
                                    <td class="text-end">₹{{ number_format((float) $item->line_total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted p-4">No products on this purchase order.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div
            class="tab-pane fade{{ $activeTab === 'receiving' ? ' show active' : '' }}"
            id="po-pane-receiving"
            role="tabpanel"
            aria-labelledby="po-tab-receiving"
            tabindex="0"
        >
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

        <div
            class="tab-pane fade{{ $activeTab === 'payments' ? ' show active' : '' }}"
            id="po-pane-payments"
            role="tabpanel"
            aria-labelledby="po-tab-payments"
            tabindex="0"
        >
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                    <span>Supplier invoices &amp; payments</span>
                    @if(! in_array($purchaseOrder->status->value, ['draft', 'cancelled'], true))
                        @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_INVOICE)
                            <a href="{{ route('purchasing.supplier-invoices.create', $purchaseOrder) }}" class="btn btn-sm btn-outline-primary">Record supplier invoice</a>
                        @endcan
                    @endif
                </div>
                @forelse($purchaseOrder->supplierInvoices as $invoice)
                    <div class="border-bottom">
                        <div class="p-3 d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <a href="{{ route('purchasing.supplier-invoices.show', $invoice) }}" class="fw-semibold text-decoration-none">
                                    {{ $invoice->supplier_invoice_number }}
                                </a>
                                <div class="text-muted small">
                                    {{ $invoice->invoice_date->format('Y-m-d') }} · {{ $invoice->payment_status->label() }}
                                </div>
                            </div>
                            <div class="text-end">
                                <div>₹{{ number_format((float) $invoice->invoice_amount, 2) }}</div>
                                <div class="text-muted small">Paid ₹{{ number_format((float) $invoice->amount_paid, 2) }}</div>
                            </div>
                        </div>
                        @if($invoice->payments->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-sm mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Method</th>
                                            <th>Reference</th>
                                            <th class="text-end">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($invoice->payments as $payment)
                                            <tr>
                                                <td>{{ $payment->payment_date->format('Y-m-d') }}</td>
                                                <td>{{ $payment->payment_method }}</td>
                                                <td>{{ $payment->transaction_reference ?? '—' }}</td>
                                                <td class="text-end">₹{{ number_format((float) $payment->amount, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="p-4 text-muted">No supplier invoices recorded for this purchase order.</div>
                @endforelse
            </div>
        </div>

        <div
            class="tab-pane fade{{ $activeTab === 'activity' ? ' show active' : '' }}"
            id="po-pane-activity"
            role="tabpanel"
            aria-labelledby="po-tab-activity"
            tabindex="0"
        >
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
