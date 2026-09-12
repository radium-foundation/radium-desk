@extends('layouts.app')

@section('title', 'New purchase order')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">New purchase order</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <form method="POST" action="{{ route('purchasing.purchase-orders.store') }}" class="card border-0 shadow-sm p-4" id="po-create-form">
        @csrf
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label">Vendor</label>
                <select name="vendor_id" class="form-select" required>
                    @foreach($vendors as $vendor)
                        <option value="{{ $vendor->id }}" @selected((int) old('vendor_id') === $vendor->id)>{{ $vendor->business_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Receiving branch</label>
                <select name="branch_id" class="form-select" required>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) old('branch_id', $branches->first()?->id) === $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">PO date</label>
                <input type="date" name="po_date" class="form-control" value="{{ old('po_date', now()->toDateString()) }}" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Expected delivery</label>
                <input type="date" name="expected_delivery_date" class="form-control" value="{{ old('expected_delivery_date') }}">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
            </div>
        </div>

        <h2 class="h5">Products</h2>
        <div class="mb-3 position-relative" style="max-width: 32rem;">
            <label class="form-label" for="po-product-search">Search product by SKU or name</label>
            <input type="text" id="po-product-search" class="form-control" placeholder="Type to search…" autocomplete="off">
            <div id="po-product-results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 10; max-height: 16rem; overflow-y: auto;" hidden></div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table align-middle" id="po-lines-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width: 7rem;">Qty</th>
                        <th style="width: 8rem;">Unit cost</th>
                        <th style="width: 6rem;">Tax %</th>
                        <th style="width: 8rem;">Discount</th>
                        <th style="width: 4rem;"></th>
                    </tr>
                </thead>
                <tbody id="po-lines-body">
                    <tr id="po-lines-empty">
                        <td colspan="6" class="text-muted">Search and add products above.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        @error('lines')
            <div class="text-danger small mb-2">{{ $message }}</div>
        @enderror

        <button class="btn btn-primary" type="submit">Create draft PO</button>
    </form>
@endsection

@push('scripts')
    <script>
        (function () {
            const searchUrl = @json($searchProductsUrl);
            const productInput = document.getElementById('po-product-search');
            const productResults = document.getElementById('po-product-results');
            const linesBody = document.getElementById('po-lines-body');
            const linesEmpty = document.getElementById('po-lines-empty');
            const form = document.getElementById('po-create-form');

            let lineIndex = 0;
            let searchTimer = null;
            const lines = new Map();

            function lineKey(productId) {
                return String(productId);
            }

            function renderEmptyState() {
                linesEmpty.hidden = lines.size > 0;
            }

            function addLine(product) {
                const key = lineKey(product.id);
                if (lines.has(key)) {
                    window.alert(product.sku + ' is already on this PO. Adjust the quantity on the existing row.');
                    productInput.value = '';
                    productResults.hidden = true;
                    return;
                }

                const index = lineIndex++;
                lines.set(key, index);

                const row = document.createElement('tr');
                row.dataset.productId = product.id;
                row.innerHTML =
                    '<td>' +
                        '<input type="hidden" name="lines[' + index + '][product_id]" value="' + product.id + '">' +
                        '<div class="fw-semibold">' + product.sku + '</div>' +
                        '<div class="small text-muted">' + product.name + '</div>' +
                        (product.is_serialized ? '<span class="badge text-bg-secondary mt-1">Serialized</span>' : '<span class="badge text-bg-light border mt-1">Quantity</span>') +
                    '</td>' +
                    '<td><input type="number" name="lines[' + index + '][quantity]" class="form-control" min="1" value="1" required></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][unit_cost]" class="form-control" min="0" value="' + product.unit_cost + '" required></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][tax_rate]" class="form-control" min="0" value="' + product.gst_percentage + '"></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][discount_amount]" class="form-control" min="0" value="0"></td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline-danger po-remove-line" data-key="' + key + '">Remove</button></td>';

                linesBody.appendChild(row);
                renderEmptyState();

                productInput.value = '';
                productResults.hidden = true;
            }

            function searchProducts(query) {
                fetch(searchUrl + '?q=' + encodeURIComponent(query), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('search failed');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        productResults.innerHTML = '';
                        const products = payload.products || [];
                        if (products.length === 0) {
                            productResults.innerHTML = '<div class="list-group-item text-muted">No products found.</div>';
                        } else {
                            products.forEach(function (product) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.innerHTML =
                                    '<div class="fw-semibold">' + product.sku + '</div>' +
                                    '<div class="small text-muted">' + product.name + (product.is_serialized ? ' · Serialized' : ' · Quantity') + '</div>';
                                button.addEventListener('click', function () {
                                    addLine(product);
                                });
                                productResults.appendChild(button);
                            });
                        }
                        productResults.hidden = false;
                    })
                    .catch(function () {
                        productResults.innerHTML = '<div class="list-group-item text-danger">Product search failed.</div>';
                        productResults.hidden = false;
                    });
            }

            productInput.addEventListener('input', function () {
                const query = productInput.value.trim();
                clearTimeout(searchTimer);
                if (query.length < 1) {
                    productResults.hidden = true;
                    return;
                }
                searchTimer = setTimeout(function () {
                    searchProducts(query);
                }, 200);
            });

            document.addEventListener('click', function (event) {
                if (!productResults.contains(event.target) && event.target !== productInput) {
                    productResults.hidden = true;
                }
            });

            linesBody.addEventListener('click', function (event) {
                const button = event.target.closest('.po-remove-line');
                if (!button) {
                    return;
                }
                const key = button.dataset.key;
                lines.delete(key);
                button.closest('tr').remove();
                renderEmptyState();
            });

            form.addEventListener('submit', function (event) {
                if (lines.size === 0) {
                    event.preventDefault();
                    window.alert('Add at least one product line with quantity at least 1.');
                }
            });
        })();
    </script>
@endpush
