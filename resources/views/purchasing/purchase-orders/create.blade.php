@extends('layouts.app')

@section('title', 'New purchase order')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">New purchase order</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <div class="alert alert-info">
        PO number is assigned automatically when you save (format <code>PO-07-###</code> for FY 2026–27). Please verify all PO lines before saving or sending — draft editing is not currently available.
    </div>

    <form method="POST" action="{{ route('purchasing.purchase-orders.store') }}" class="card border-0 shadow-sm p-4" id="po-create-form">
        @csrf
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label class="form-label" for="po-vendor-search">Vendor</label>
                <input type="hidden" name="vendor_id" id="po-vendor-id" value="{{ old('vendor_id') }}" required>
                <input type="text" id="po-vendor-search" class="form-control @error('vendor_id') is-invalid @enderror" placeholder="Type vendor name / GSTIN / phone…" autocomplete="off" value="{{ old('vendor_label') }}">
                <div id="po-vendor-results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 11; max-height: 16rem; overflow-y: auto;" hidden></div>
                @error('vendor_id')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
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
                <label class="form-label text-muted">PO number</label>
                <input type="text" class="form-control" value="Assigned automatically on save" readonly tabindex="-1">
            </div>
            <div class="col-md-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
            </div>
        </div>

        <h2 class="h5">Products</h2>
        <div class="mb-3 position-relative" style="max-width: 32rem;">
            <label class="form-label" for="po-product-search">Search product by SKU or name</label>
            <input type="text" id="po-product-search" class="form-control" placeholder="Type SKU / product name…" autocomplete="off">
            <div id="po-product-results" class="list-group position-absolute w-100 shadow-sm" style="z-index: 10; max-height: 16rem; overflow-y: auto;" hidden></div>
        </div>

        <div class="table-responsive mb-3">
            <table class="table align-middle" id="po-lines-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width: 7rem;">Ordered qty</th>
                        <th style="width: 8rem;">Unit cost</th>
                        <th style="width: 6rem;">Tax %</th>
                        <th style="width: 8rem;">Discount</th>
                        <th style="width: 8rem;">Line total</th>
                        <th style="width: 4rem;"></th>
                    </tr>
                </thead>
                <tbody id="po-lines-body">
                    <tr id="po-lines-empty">
                        <td colspan="7" class="text-muted">Search and add products above.</td>
                    </tr>
                </tbody>
                <tfoot id="po-totals-foot" hidden>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Subtotal</td>
                        <td colspan="2" class="fw-semibold" id="po-subtotal">0.00</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">Tax</td>
                        <td colspan="2" class="fw-semibold" id="po-tax-total">0.00</td>
                    </tr>
                    <tr>
                        <td colspan="5" class="text-end fw-semibold">PO total</td>
                        <td colspan="2" class="fw-semibold" id="po-grand-total">0.00</td>
                    </tr>
                </tfoot>
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
            const searchProductsUrl = @json($searchProductsUrl);
            const searchVendorsUrl = @json($searchVendorsUrl);
            const productInput = document.getElementById('po-product-search');
            const productResults = document.getElementById('po-product-results');
            const vendorInput = document.getElementById('po-vendor-search');
            const vendorIdInput = document.getElementById('po-vendor-id');
            const vendorResults = document.getElementById('po-vendor-results');
            const linesBody = document.getElementById('po-lines-body');
            const linesEmpty = document.getElementById('po-lines-empty');
            const totalsFoot = document.getElementById('po-totals-foot');
            const form = document.getElementById('po-create-form');

            let lineIndex = 0;
            let productSearchTimer = null;
            let vendorSearchTimer = null;
            const lines = new Map();

            function formatMoney(value) {
                return Number(value || 0).toFixed(2);
            }

            function lineKey(productId) {
                return String(productId);
            }

            function renderEmptyState() {
                linesEmpty.hidden = lines.size > 0;
                totalsFoot.hidden = lines.size === 0;
            }

            function lineAmountsFromRow(row) {
                const qty = Number(row.querySelector('[data-field="quantity"]').value || 0);
                const unitCost = Number(row.querySelector('[data-field="unit_cost"]').value || 0);
                const taxRate = Number(row.querySelector('[data-field="tax_rate"]').value || 0);
                const discount = Number(row.querySelector('[data-field="discount_amount"]').value || 0);
                const taxable = Math.max(0, (qty * unitCost) - discount);
                const tax = taxable * (taxRate / 100);
                const total = taxable + tax;

                return { taxable, tax, total };
            }

            function recalculateTotals() {
                let subtotal = 0;
                let taxTotal = 0;

                linesBody.querySelectorAll('tr[data-product-id]').forEach(function (row) {
                    const amounts = lineAmountsFromRow(row);
                    subtotal += amounts.taxable;
                    taxTotal += amounts.tax;
                    row.querySelector('[data-field="line_total"]').textContent = formatMoney(amounts.total);
                });

                document.getElementById('po-subtotal').textContent = formatMoney(subtotal);
                document.getElementById('po-tax-total').textContent = formatMoney(taxTotal);
                document.getElementById('po-grand-total').textContent = formatMoney(subtotal + taxTotal);
            }

            function addLine(product) {
                const key = lineKey(product.id);
                if (lines.has(key)) {
                    window.alert(product.sku + ' is already on this PO. Adjust the ordered quantity on the existing row.');
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
                        (product.is_serialized ? '<span class="badge text-bg-secondary mt-1">Serialized — serials captured at goods receipt</span>' : '') +
                    '</td>' +
                    '<td><input type="number" name="lines[' + index + '][quantity]" data-field="quantity" class="form-control" min="1" value="1" required></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][unit_cost]" data-field="unit_cost" class="form-control" min="0" value="' + product.unit_cost + '" required></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][tax_rate]" data-field="tax_rate" class="form-control" min="0" value="' + product.gst_percentage + '"></td>' +
                    '<td><input type="number" step="0.01" name="lines[' + index + '][discount_amount]" data-field="discount_amount" class="form-control" min="0" value="0"></td>' +
                    '<td class="fw-semibold" data-field="line_total">0.00</td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline-danger po-remove-line" data-key="' + key + '">Remove</button></td>';

                linesBody.appendChild(row);
                row.querySelectorAll('[data-field]').forEach(function (input) {
                    input.addEventListener('input', recalculateTotals);
                });
                renderEmptyState();
                recalculateTotals();

                productInput.value = '';
                productResults.hidden = true;
            }

            function selectVendor(vendor) {
                vendorIdInput.value = String(vendor.id);
                vendorInput.value = vendor.business_name;
                vendorResults.hidden = true;
            }

            function searchProducts(query) {
                fetch(searchProductsUrl + '?q=' + encodeURIComponent(query), {
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
                                    '<div class="small text-muted">' + product.name + (product.is_serialized ? ' · Serialized' : '') + '</div>';
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
                        vendorResults.hidden = true;
                    });
            }

            productInput.addEventListener('input', function () {
                const query = productInput.value.trim();
                clearTimeout(productSearchTimer);
                if (query.length < 1) {
                    productResults.hidden = true;
                    return;
                }
                productSearchTimer = setTimeout(function () {
                    searchProducts(query);
                }, 200);
            });

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
                if (!productResults.contains(event.target) && event.target !== productInput) {
                    productResults.hidden = true;
                }
                if (!vendorResults.contains(event.target) && event.target !== vendorInput) {
                    vendorResults.hidden = true;
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
                recalculateTotals();
            });

            form.addEventListener('submit', function (event) {
                if (!vendorIdInput.value) {
                    event.preventDefault();
                    window.alert('Select a vendor from Vendor Master search results.');
                    return;
                }
                if (lines.size === 0) {
                    event.preventDefault();
                    window.alert('Add at least one product line with ordered quantity at least 1.');
                }
            });
        })();
    </script>
@endpush
