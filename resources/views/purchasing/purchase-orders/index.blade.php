@extends('layouts.app')

@section('title', 'Purchase Orders')

@section('content')
    <div class="mb-4 d-flex justify-content-between flex-wrap gap-2">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
            <h1 class="h3 mb-1">Purchase Orders</h1>
        </div>
        @can(\Database\Seeders\RolePermissionSeeder::PERMISSION_PURCHASE_CREATE)
            <a href="{{ route('purchasing.purchase-orders.create') }}" class="btn btn-primary">New PO</a>
        @endcan
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <form method="GET" class="row g-2 mb-3" id="po-index-filter-form">
        <div class="col-md-3">
            <input type="text" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}" placeholder="PO number">
        </div>
        <div class="col-md-4 position-relative">
            <input type="hidden" name="vendor_id" id="po-index-vendor-id" value="{{ $filters['vendor_id'] ?? '' }}">
            <input
                type="text"
                id="po-index-vendor-search"
                class="form-control"
                placeholder="Type vendor name / GSTIN / phone…"
                autocomplete="off"
                value="{{ $selectedVendor?->business_name }}"
            >
            <div id="po-index-vendor-results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 11; max-height: 16rem; overflow-y: auto;" hidden></div>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select">
                <option value="">All statuses</option>
                @foreach(\App\Enums\PurchaseOrderStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>PO</th>
                        <th>Vendor</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $order)
                        <tr>
                            <td>{{ $order->po_number }}</td>
                            <td>{{ $order->vendor->business_name }}</td>
                            <td>{{ $order->po_date->format('Y-m-d') }}</td>
                            <td>{{ $order->status->label() }}</td>
                            <td class="text-end">₹{{ number_format((float) $order->grand_total, 2) }}</td>
                            <td><a href="{{ route('purchasing.purchase-orders.show', $order) }}">Open</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted p-4">No purchase orders yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $orders->links() }}</div>
@endsection

@push('scripts')
    <script>
        (function () {
            const searchVendorsUrl = @json($searchVendorsUrl);
            const vendorInput = document.getElementById('po-index-vendor-search');
            const vendorIdInput = document.getElementById('po-index-vendor-id');
            const vendorResults = document.getElementById('po-index-vendor-results');
            let vendorSearchTimer = null;

            function selectVendor(vendor) {
                vendorIdInput.value = String(vendor.id);
                vendorInput.value = vendor.business_name;
                vendorResults.hidden = true;
            }

            function searchVendors(query) {
                fetch(searchVendorsUrl + '?q=' + encodeURIComponent(query), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('search failed');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        vendorResults.innerHTML = '';
                        const vendors = payload.vendors || [];
                        if (vendors.length === 0) {
                            vendorResults.innerHTML = '<div class="list-group-item text-muted">No vendors found.</div>';
                        } else {
                            vendors.forEach(function (vendor) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.innerHTML =
                                    '<div class="fw-semibold">' + vendor.business_name + '</div>' +
                                    '<div class="small text-muted">' +
                                        (vendor.gstin ? 'GSTIN: ' + vendor.gstin : 'GSTIN: —') +
                                        (vendor.phone ? ' · Phone: ' + vendor.phone : '') +
                                    '</div>';
                                button.addEventListener('click', function () {
                                    selectVendor(vendor);
                                });
                                vendorResults.appendChild(button);
                            });
                        }
                        vendorResults.hidden = false;
                    })
                    .catch(function () {
                        vendorResults.innerHTML = '<div class="list-group-item text-danger">Vendor search failed.</div>';
                        vendorResults.hidden = false;
                    });
            }

            vendorInput.addEventListener('input', function () {
                vendorIdInput.value = '';
                const query = vendorInput.value.trim();
                clearTimeout(vendorSearchTimer);
                if (query.length < 1) {
                    vendorResults.hidden = true;
                    return;
                }
                vendorSearchTimer = setTimeout(function () {
                    searchVendors(query);
                }, 200);
            });

            document.addEventListener('click', function (event) {
                if (!vendorResults.contains(event.target) && event.target !== vendorInput) {
                    vendorResults.hidden = true;
                }
            });
        })();
    </script>
@endpush
