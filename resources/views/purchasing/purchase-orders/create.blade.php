@extends('layouts.app')

@section('title', 'New purchase order')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Purchasing</p>
        <h1 class="h3 mb-1">New purchase order</h1>
    </div>

    @include('purchasing.partials.workspace-nav', ['active' => 'purchase_orders'])

    <form method="POST" action="{{ route('purchasing.purchase-orders.store') }}" id="po-create-form" class="card border-0 shadow-sm p-4">
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
        <div class="mb-3">
            <label class="form-label" for="po-product-search">Search product / SKU</label>
            <input
                type="search"
                id="po-product-search"
                class="form-control"
                placeholder="Search product / SKU..."
                autocomplete="off"
            >
            <div id="po-product-results" class="list-group mt-2 d-none"></div>
            @error('lines')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        </div>

        <div class="table-responsive mb-3">
            <table class="table align-middle" id="po-lines-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="width: 7rem;">Qty</th>
                        <th style="width: 8rem;">Unit cost</th>
                        <th style="width: 7rem;">Tax %</th>
                        <th style="width: 8rem;">Discount</th>
                        <th style="width: 3rem;"></th>
                    </tr>
                </thead>
                <tbody id="po-lines-body">
                    <tr id="po-lines-empty">
                        <td colspan="6" class="text-muted">No products added yet. Search above to add lines.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div id="po-line-fields"></div>
        <p class="text-muted small">Search and add at least one product line before saving.</p>
        <button type="submit" class="btn btn-primary" id="po-create-submit">Create draft PO</button>
    </form>
@endsection

@push('scripts')
    <script>
        (function () {
            const productSearchUrl = @json($searchProductsUrl);
            const productInput = document.getElementById('po-product-search');
            const productResults = document.getElementById('po-product-results');
            const linesBody = document.getElementById('po-lines-body');
            const linesEmpty = document.getElementById('po-lines-empty');
            const lineFields = document.getElementById('po-line-fields');
            const form = document.getElementById('po-create-form');

            let lines = [];
            let searchTimer = null;

            function lineKey(productId, variantId) {
                return String(productId) + ':' + String(variantId || 0);
            }

            function money(value) {
                return (Math.round(value * 100) / 100).toFixed(2);
            }

            function selectInputValue(input) {
                if (!input || typeof input.select !== 'function') {
                    return;
                }
                try {
                    input.select();
                } catch (error) {
                    // Some browsers reject select() on number inputs; ignore.
                }
            }

            function focusLineField(lineIndex, field) {
                const row = linesBody.querySelector('tr.po-line-row[data-line-index="' + lineIndex + '"]');
                if (!row) {
                    return;
                }
                const input = row.querySelector('.po-line-' + field);
                if (!input) {
                    return;
                }
                input.focus();
                selectInputValue(input);
            }

            function syncHiddenFields() {
                lineFields.innerHTML = '';
                lines.forEach(function (line, index) {
                    const add = function (name, value) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'lines[' + index + '][' + name + ']';
                        input.value = value;
                        lineFields.appendChild(input);
                    };
                    add('product_id', line.product_id);
                    if (line.variant_id) {
                        add('variant_id', line.variant_id);
                    }
                    add('quantity', line.quantity);
                    add('unit_cost', money(line.unit_cost));
                    add('tax_rate', money(line.tax_rate));
                    add('discount_amount', money(line.discount_amount));
                });
            }

            function updateLineFromInput(target) {
                const index = Number(target.getAttribute('data-line-index'));
                if (Number.isNaN(index) || !lines[index]) {
                    return;
                }

                if (target.classList.contains('po-line-qty')) {
                    lines[index].quantity = Math.max(1, parseInt(target.value || '1', 10));
                } else if (target.classList.contains('po-line-unit-cost')) {
                    lines[index].unit_cost = Math.max(0, parseFloat(target.value || '0'));
                } else if (target.classList.contains('po-line-tax-rate')) {
                    lines[index].tax_rate = Math.max(0, parseFloat(target.value || '0'));
                } else if (target.classList.contains('po-line-discount')) {
                    lines[index].discount_amount = Math.max(0, parseFloat(target.value || '0'));
                }

                syncHiddenFields();
            }

            function normalizeLineInput(target) {
                const index = Number(target.getAttribute('data-line-index'));
                if (Number.isNaN(index) || !lines[index]) {
                    return;
                }

                if (target.classList.contains('po-line-qty')) {
                    target.value = String(lines[index].quantity);
                } else if (target.classList.contains('po-line-unit-cost')) {
                    target.value = money(lines[index].unit_cost);
                } else if (target.classList.contains('po-line-tax-rate')) {
                    target.value = money(lines[index].tax_rate);
                } else if (target.classList.contains('po-line-discount')) {
                    target.value = money(lines[index].discount_amount);
                }
            }

            function renderLines(focusTarget) {
                linesBody.querySelectorAll('tr.po-line-row').forEach(function (row) {
                    row.remove();
                });

                if (!lines.length) {
                    linesEmpty.hidden = false;
                } else {
                    linesEmpty.hidden = true;
                    lines.forEach(function (line, index) {
                        const row = document.createElement('tr');
                        row.className = 'po-line-row';
                        row.setAttribute('data-line-index', String(index));
                        row.innerHTML =
                            '<td>' + line.label + '</td>' +
                            '<td><input type="number" inputmode="numeric" class="form-control po-line-qty po-line-field" data-line-index="' + index + '" data-field="qty" min="1" step="1" value="' + line.quantity + '" required></td>' +
                            '<td><input type="number" inputmode="decimal" class="form-control po-line-unit-cost po-line-field" data-line-index="' + index + '" data-field="unit_cost" min="0" step="0.01" value="' + money(line.unit_cost) + '" required></td>' +
                            '<td><input type="number" inputmode="decimal" class="form-control po-line-tax-rate po-line-field" data-line-index="' + index + '" data-field="tax_rate" min="0" step="0.01" value="' + money(line.tax_rate) + '"></td>' +
                            '<td><input type="number" inputmode="decimal" class="form-control po-line-discount po-line-field" data-line-index="' + index + '" data-field="discount" min="0" step="0.01" value="' + money(line.discount_amount) + '"></td>' +
                            '<td><button type="button" class="btn btn-sm btn-outline-danger po-line-remove" data-line-index="' + index + '" aria-label="Remove line">&times;</button></td>';
                        linesBody.appendChild(row);
                    });
                }

                syncHiddenFields();

                if (focusTarget && typeof focusTarget.lineIndex === 'number') {
                    focusLineField(focusTarget.lineIndex, focusTarget.field || 'qty');
                }
            }

            function addLine(product, variant) {
                const variantId = variant ? variant.id : null;
                const key = lineKey(product.id, variantId);
                let focusIndex = 0;

                const existingIndex = lines.findIndex(function (line) {
                    return lineKey(line.product_id, line.variant_id) === key;
                });

                if (existingIndex !== -1) {
                    lines[existingIndex].quantity += 1;
                    focusIndex = existingIndex;
                } else {
                    const label = variant
                        ? product.sku + ' / ' + variant.sku + ' — ' + product.name + ' (' + variant.name + ')'
                        : product.sku + ' — ' + product.name;
                    lines.push({
                        product_id: product.id,
                        variant_id: variantId,
                        label: label,
                        quantity: 1,
                        unit_cost: variant ? variant.unit_cost : product.unit_cost,
                        tax_rate: product.gst_percentage,
                        discount_amount: 0,
                    });
                    focusIndex = lines.length - 1;
                }

                renderLines({ lineIndex: focusIndex, field: 'qty' });
                productInput.value = '';
                productResults.classList.add('d-none');
            }

            function showProductResults(products) {
                productResults.innerHTML = '';
                if (!products.length) {
                    productResults.innerHTML = '<div class="list-group-item text-muted">No matching products.</div>';
                    productResults.classList.remove('d-none');
                    return;
                }

                products.forEach(function (product) {
                    const choices = product.variants && product.variants.length
                        ? product.variants.map(function (variant) {
                            return {
                                product: product,
                                variant: variant,
                                label: product.sku + ' / ' + variant.sku + ' — ' + product.name + ' (' + variant.name + ')',
                            };
                        })
                        : [{ product: product, variant: null, label: product.sku + ' — ' + product.name }];

                    choices.forEach(function (choice) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'list-group-item list-group-item-action';
                        button.textContent = choice.label;
                        button.addEventListener('click', function () {
                            addLine(choice.product, choice.variant);
                        });
                        productResults.appendChild(button);
                    });
                });
                productResults.classList.remove('d-none');
            }

            function loadProducts(query) {
                const url = productSearchUrl + '?q=' + encodeURIComponent(query);
                fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('product-search-failed');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        showProductResults(data.products || []);
                    })
                    .catch(function () {
                        productResults.innerHTML = '<div class="list-group-item text-danger">Could not search products. Try again.</div>';
                        productResults.classList.remove('d-none');
                    });
            }

            productInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                const query = productInput.value.trim();
                if (!query) {
                    productResults.classList.add('d-none');
                    return;
                }
                searchTimer = setTimeout(function () {
                    loadProducts(query);
                }, 250);
            });

            productInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    productResults.classList.add('d-none');
                }
            });

            linesBody.addEventListener('input', function (event) {
                const target = event.target;
                if (!target.classList.contains('po-line-field')) {
                    return;
                }
                updateLineFromInput(target);
            });

            linesBody.addEventListener('blur', function (event) {
                const target = event.target;
                if (!target.classList.contains('po-line-field')) {
                    return;
                }
                updateLineFromInput(target);
                normalizeLineInput(target);
            }, true);

            linesBody.addEventListener('focusin', function (event) {
                const target = event.target;
                if (!target.classList.contains('po-line-field')) {
                    return;
                }
                selectInputValue(target);
            });

            linesBody.addEventListener('keydown', function (event) {
                const target = event.target;
                if (!target.classList.contains('po-line-field')) {
                    return;
                }

                if (event.key === 'Enter') {
                    event.preventDefault();
                    const field = target.getAttribute('data-field');
                    const lineIndex = Number(target.getAttribute('data-line-index'));
                    const order = ['qty', 'unit_cost', 'tax_rate', 'discount'];
                    const position = order.indexOf(field);
                    if (position === -1) {
                        return;
                    }
                    if (position < order.length - 1) {
                        focusLineField(lineIndex, order[position + 1]);
                        return;
                    }
                    if (lineIndex + 1 < lines.length) {
                        focusLineField(lineIndex + 1, 'qty');
                        return;
                    }
                    productInput.focus();
                }
            });

            linesBody.addEventListener('click', function (event) {
                const button = event.target.closest('.po-line-remove');
                if (!button) {
                    return;
                }
                const index = Number(button.getAttribute('data-line-index'));
                if (Number.isNaN(index)) {
                    return;
                }
                lines.splice(index, 1);
                renderLines();
            });

            form.addEventListener('submit', function (event) {
                if (!lines.length) {
                    event.preventDefault();
                    window.alert('Add at least one product line before saving.');
                }
            });

            document.addEventListener('click', function (event) {
                if (!productResults.contains(event.target) && event.target !== productInput) {
                    productResults.classList.add('d-none');
                }
            });
        })();
    </script>
@endpush
