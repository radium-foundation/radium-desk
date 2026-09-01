@extends('layouts.app')

@section('title', 'POS counter')

@section('content')
    <div class="mb-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
            <h1 class="h3 mb-1">Retail counter</h1>
            <p class="text-muted mb-0">Search products, add them to the cart, then complete the sale. Stock is taken only when the sale succeeds.</p>
        </div>
        @if($operatingBranch)
            <div class="border rounded px-3 py-2 bg-body-secondary">
                <div class="small text-muted text-uppercase fw-semibold">Selling from</div>
                <div class="fw-semibold">{{ $operatingBranch->code }} — {{ $operatingBranch->name }}</div>
            </div>
        @endif
    </div>

    @include('pos.partials.workspace-nav', ['active' => 'counter'])
    @include('inventory.partials.branch-scope-empty')

    @if($branches->isEmpty())
        <div class="alert alert-secondary">No active branches are available for this login.</div>
    @else
        <form method="GET" action="{{ route('pos.counter.create') }}" class="mb-3">
            <label class="form-label" for="operating_branch_id">Branch</label>
            <div class="d-flex flex-wrap gap-2">
                <select name="branch_id" id="operating_branch_id" class="form-select" style="max-width: 28rem" @disabled($operatingBranch && $branches->count() === 1)>
                    @if($branches->count() > 1)
                        <option value="">Select branch</option>
                    @endif
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((int) ($operatingBranch?->id) === (int) $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                    @endforeach
                </select>
                @if($branches->count() > 1)
                    <button class="btn btn-outline-secondary">Switch branch</button>
                @endif
            </div>
        </form>

        @if(!$operatingBranch)
            <div class="alert alert-info">Select a branch to start a sale. You will only see stock at that location.</div>
        @else
            <form method="POST" action="{{ route('pos.counter.store') }}" id="pos-counter-form">
                @csrf
                <input type="hidden" name="branch_id" value="{{ $operatingBranch->id }}">
                <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">

                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h2 class="h5">Add products</h2>
                                <label class="form-label" for="pos-product-search">Product / SKU search</label>
                                <input type="search" id="pos-product-search" class="form-control" placeholder="Type SKU or name" autocomplete="off">
                                <div id="pos-product-results" class="list-group mt-2 d-none"></div>
                                @error('lines')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3" id="pos-serial-card" hidden>
                            <div class="card-body">
                                <h2 class="h6" id="pos-serial-heading">Available serials</h2>
                                <label class="form-label" for="pos-serial-search">Serial search</label>
                                <input type="search" id="pos-serial-search" class="form-control" placeholder="Scan or type serial" autocomplete="off">
                                <div id="pos-serial-results" class="list-group mt-2"></div>
                                <p class="small text-muted mb-0 mt-2">One serial is one unit. Sold or reserved serials will not appear.</p>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm">
                            <div class="card-body">
                                <h2 class="h5">Cart</h2>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Price</th>
                                                <th class="text-end">Disc.</th>
                                                <th class="text-end">Line</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody id="pos-cart-body">
                                            <tr id="pos-cart-empty">
                                                <td colspan="6" class="text-muted">Cart is empty. Search a product to add it.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div id="pos-cart-fields"></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-5">
                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h2 class="h5">Customer</h2>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_phone">Phone</label>
                                    <input type="text" name="customer_phone" id="customer_phone" class="form-control" required value="{{ old('customer_phone') }}" autocomplete="off">
                                    @error('customer_phone')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_name">Name</label>
                                    <input type="text" name="customer_name" id="customer_name" class="form-control" required value="{{ old('customer_name') }}">
                                    @error('customer_name')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="form-label" for="customer_email">Email</label>
                                    <input type="email" name="customer_email" id="customer_email" class="form-control" value="{{ old('customer_email') }}">
                                </div>
                                <p class="small text-muted mb-0 mt-2" id="pos-customer-status"></p>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h2 class="h5">Payment</h2>
                                <div class="mb-2">
                                    <label class="form-label" for="payment_method">Method</label>
                                    <select name="payment_method" id="payment_method" class="form-select" required>
                                        @foreach($paymentMethods as $method)
                                            <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="payment_reference">Reference</label>
                                    <input type="text" name="payment_reference" id="payment_reference" class="form-control" value="{{ old('payment_reference') }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="discount">Header discount</label>
                                    <input type="number" step="0.01" min="0" name="discount" id="discount" class="form-control" value="{{ old('discount', 0) }}">
                                    @error('discount')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="form-label" for="notes">Notes</label>
                                    <input type="text" name="notes" id="notes" class="form-control" value="{{ old('notes') }}">
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm mb-3">
                            <div class="card-body">
                                <h2 class="h5">Totals</h2>
                                <div class="d-flex justify-content-between"><span>Subtotal</span><span id="pos-subtotal">0.00</span></div>
                                <div class="d-flex justify-content-between"><span>Discount</span><span id="pos-discount">0.00</span></div>
                                <div class="d-flex justify-content-between"><span>Tax</span><span id="pos-tax">0.00</span></div>
                                <div class="d-flex justify-content-between fw-semibold fs-5 mt-2"><span>Total</span><span id="pos-total">0.00</span></div>
                                <p class="small text-muted mb-0 mt-2">Internal invoice only — not a GST e-invoice.</p>
                            </div>
                        </div>

                        <button class="btn btn-primary w-100" id="pos-complete" type="submit">Complete sale</button>
                        <a href="{{ route('pos.sales.index') }}" class="btn btn-outline-secondary w-100 mt-2">Sale history</a>
                    </div>
                </div>
            </form>
        @endif
    @endif
@endsection

@push('scripts')
    @if($operatingBranch)
        <script>
            (function () {
                const branchId = @json($operatingBranch->id);
                const productSearchUrl = @json($searchProductsUrl);
                const serialSearchUrl = @json($searchSerialsUrl);
                const customerLookupUrl = @json($lookupCustomerUrl);
                const oldLines = @json(array_values(old('lines', [])));

                const productInput = document.getElementById('pos-product-search');
                const productResults = document.getElementById('pos-product-results');
                const serialCard = document.getElementById('pos-serial-card');
                const serialHeading = document.getElementById('pos-serial-heading');
                const serialInput = document.getElementById('pos-serial-search');
                const serialResults = document.getElementById('pos-serial-results');
                const cartBody = document.getElementById('pos-cart-body');
                const cartEmpty = document.getElementById('pos-cart-empty');
                const cartFields = document.getElementById('pos-cart-fields');
                const headerDiscount = document.getElementById('discount');
                const phoneInput = document.getElementById('customer_phone');
                const nameInput = document.getElementById('customer_name');
                const emailInput = document.getElementById('customer_email');
                const customerStatus = document.getElementById('pos-customer-status');
                const form = document.getElementById('pos-counter-form');

                let cart = [];
                let pendingProduct = null;
                let searchTimer = null;
                let serialTimer = null;
                let phoneTimer = null;

                function money(value) {
                    return (Math.round(value * 100) / 100).toFixed(2);
                }

                function lineTotals(item) {
                    const lineSubtotal = item.unit_price * item.qty;
                    const taxable = Math.max(0, lineSubtotal - item.discount);
                    const tax = taxable * (item.gst_percentage / 100);
                    return { lineSubtotal, tax, lineTotal: taxable + tax };
                }

                function renderCart() {
                    cartBody.querySelectorAll('tr.pos-cart-row').forEach(function (row) { row.remove(); });
                    cartEmpty.hidden = cart.length > 0;
                    cart.forEach(function (item, index) {
                        const totals = lineTotals(item);
                        const row = document.createElement('tr');
                        row.className = 'pos-cart-row';
                        const serialNote = item.serials.length ? '<div class="small text-muted">' + item.serials.join(', ') + '</div>' : '';
                        const variantNote = item.variant_name ? ' · ' + item.variant_name : '';
                        row.innerHTML =
                            '<td>' + item.sku + ' — ' + item.name + variantNote + serialNote + '</td>' +
                            '<td class="text-end"><input type="number" min="1" class="form-control form-control-sm text-end pos-qty" data-index="' + index + '" value="' + item.qty + '"' + (item.is_serialized ? ' readonly' : '') + '></td>' +
                            '<td class="text-end"><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pos-price" data-index="' + index + '" value="' + money(item.unit_price) + '"></td>' +
                            '<td class="text-end"><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pos-line-discount" data-index="' + index + '" value="' + money(item.discount) + '"></td>' +
                            '<td class="text-end">' + money(totals.lineTotal) + '</td>' +
                            '<td><button type="button" class="btn btn-sm btn-outline-danger pos-remove" data-index="' + index + '">Remove</button></td>';
                        cartBody.appendChild(row);
                    });
                    renderTotals();
                    syncFields();
                }

                function renderTotals() {
                    let subtotal = 0;
                    let lineDiscount = 0;
                    let tax = 0;
                    cart.forEach(function (item) {
                        const totals = lineTotals(item);
                        subtotal += totals.lineSubtotal;
                        lineDiscount += item.discount;
                        tax += totals.tax;
                    });
                    const header = parseFloat(headerDiscount.value || '0') || 0;
                    const discount = header + lineDiscount;
                    const total = subtotal - discount + tax;
                    document.getElementById('pos-subtotal').textContent = money(subtotal);
                    document.getElementById('pos-discount').textContent = money(discount);
                    document.getElementById('pos-tax').textContent = money(tax);
                    document.getElementById('pos-total').textContent = money(Math.max(0, total));
                }

                function syncFields() {
                    cartFields.innerHTML = '';
                    cart.forEach(function (item, index) {
                        const add = function (name, value) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'lines[' + index + '][' + name + ']';
                            input.value = value;
                            cartFields.appendChild(input);
                        };
                        add('product_id', item.product_id);
                        if (item.variant_id) {
                            add('variant_id', item.variant_id);
                        }
                        add('qty', item.qty);
                        add('unit_price', money(item.unit_price));
                        add('discount', money(item.discount));
                        add('gst_percentage', String(item.gst_percentage));
                        add('serials', item.serials.join('\n'));
                    });
                }

                function addQuantityItem(product, variant) {
                    const variantId = variant ? variant.id : null;
                    const existing = cart.find(function (item) {
                        return item.product_id === product.id && item.variant_id === variantId && !item.is_serialized;
                    });
                    if (existing) {
                        existing.qty += 1;
                    } else {
                        cart.push({
                            product_id: product.id,
                            variant_id: variantId,
                            sku: variant && variant.sku ? variant.sku : product.sku,
                            name: product.name,
                            variant_name: variant ? variant.name : '',
                            is_serialized: false,
                            gst_percentage: product.gst_percentage,
                            unit_price: variant ? variant.unit_price : product.unit_price,
                            qty: 1,
                            discount: 0,
                            serials: [],
                        });
                    }
                    renderCart();
                    productInput.value = '';
                    productResults.classList.add('d-none');
                }

                function addSerializedItem(product, variant, serialNumber) {
                    const variantId = variant ? variant.id : null;
                    if (cart.some(function (item) { return item.serials.indexOf(serialNumber) !== -1; })) {
                        return;
                    }
                    cart.push({
                        product_id: product.id,
                        variant_id: variantId,
                        sku: variant && variant.sku ? variant.sku : product.sku,
                        name: product.name,
                        variant_name: variant ? variant.name : '',
                        is_serialized: true,
                        gst_percentage: product.gst_percentage,
                        unit_price: variant ? variant.unit_price : product.unit_price,
                        qty: 1,
                        discount: 0,
                        serials: [serialNumber],
                    });
                    renderCart();
                }

                function showProductResults(products) {
                    productResults.innerHTML = '';
                    if (!products.length) {
                        productResults.classList.add('d-none');
                        return;
                    }
                    products.forEach(function (product) {
                        const choices = product.variants && product.variants.length ? product.variants.map(function (variant) {
                            return { product: product, variant: variant, label: product.sku + ' / ' + variant.sku + ' — ' + product.name + ' (' + variant.name + ')', available: variant.available_qty };
                        }) : [{ product: product, variant: null, label: product.sku + ' — ' + product.name, available: product.available_qty }];
                        choices.forEach(function (choice) {
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                            button.innerHTML = '<span>' + choice.label + (choice.product.is_serialized ? ' · serial' : '') + '</span><span class="text-muted">' + choice.available + ' available</span>';
                            button.addEventListener('click', function () {
                                if (choice.product.is_serialized) {
                                    pendingProduct = choice;
                                    serialCard.hidden = false;
                                    serialHeading.textContent = 'Serials for ' + choice.label;
                                    serialInput.value = '';
                                    serialInput.focus();
                                    loadSerials('');
                                    productResults.classList.add('d-none');
                                } else {
                                    addQuantityItem(choice.product, choice.variant);
                                }
                            });
                            productResults.appendChild(button);
                        });
                    });
                    productResults.classList.remove('d-none');
                }

                function loadProducts(query) {
                    const url = productSearchUrl + '?branch_id=' + encodeURIComponent(branchId) + '&q=' + encodeURIComponent(query);
                    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) { return response.json(); })
                        .then(function (data) { showProductResults(data.products || []); });
                }

                function loadSerials(query) {
                    if (!pendingProduct) {
                        return;
                    }
                    const params = new URLSearchParams({
                        branch_id: String(branchId),
                        q: query,
                        product_id: String(pendingProduct.product.id),
                    });
                    if (pendingProduct.variant) {
                        params.set('variant_id', String(pendingProduct.variant.id));
                    }
                    fetch(serialSearchUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) { return response.json(); })
                        .then(function (data) {
                            serialResults.innerHTML = '';
                            (data.serials || []).forEach(function (serial) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.textContent = serial.serial_number;
                                button.addEventListener('click', function () {
                                    addSerializedItem(pendingProduct.product, pendingProduct.variant, serial.serial_number);
                                    loadSerials(serialInput.value);
                                });
                                serialResults.appendChild(button);
                            });
                            if (!(data.serials || []).length) {
                                serialResults.innerHTML = '<div class="list-group-item text-muted">No available serials at this branch.</div>';
                            }
                        });
                }

                productInput.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    const query = productInput.value.trim();
                    if (!query) {
                        productResults.classList.add('d-none');
                        return;
                    }
                    searchTimer = setTimeout(function () { loadProducts(query); }, 200);
                });

                serialInput.addEventListener('input', function () {
                    clearTimeout(serialTimer);
                    serialTimer = setTimeout(function () { loadSerials(serialInput.value.trim()); }, 200);
                });

                cartBody.addEventListener('click', function (event) {
                    const button = event.target.closest('.pos-remove');
                    if (!button) {
                        return;
                    }
                    cart.splice(parseInt(button.getAttribute('data-index'), 10), 1);
                    renderCart();
                });

                cartBody.addEventListener('input', function (event) {
                    const field = event.target;
                    const index = parseInt(field.getAttribute('data-index'), 10);
                    if (Number.isNaN(index) || !cart[index]) {
                        return;
                    }
                    if (field.classList.contains('pos-qty')) {
                        cart[index].qty = Math.max(1, parseInt(field.value, 10) || 1);
                    }
                    if (field.classList.contains('pos-price')) {
                        cart[index].unit_price = Math.max(0, parseFloat(field.value) || 0);
                    }
                    if (field.classList.contains('pos-line-discount')) {
                        cart[index].discount = Math.max(0, parseFloat(field.value) || 0);
                    }
                    renderTotals();
                    syncFields();
                });

                headerDiscount.addEventListener('input', renderTotals);

                phoneInput.addEventListener('input', function () {
                    clearTimeout(phoneTimer);
                    const phone = phoneInput.value.replace(/\s+/g, '');
                    if (phone.length < 8) {
                        customerStatus.textContent = '';
                        return;
                    }
                    phoneTimer = setTimeout(function () {
                        fetch(customerLookupUrl + '?phone=' + encodeURIComponent(phone), { headers: { 'Accept': 'application/json' } })
                            .then(function (response) { return response.json(); })
                            .then(function (data) {
                                if (data.found) {
                                    nameInput.value = data.name || nameInput.value;
                                    emailInput.value = data.email || emailInput.value;
                                    customerStatus.textContent = 'Existing POS customer loaded.';
                                } else {
                                    customerStatus.textContent = 'New customer will be created on complete.';
                                }
                            });
                    }, 250);
                });

                form.addEventListener('submit', function (event) {
                    syncFields();
                    if (!cart.length) {
                        event.preventDefault();
                        alert('Add at least one item to the cart.');
                    }
                });

                if (Array.isArray(oldLines) && oldLines.length) {
                    oldLines.forEach(function (line) {
                        if (!line.product_id) {
                            return;
                        }
                        const serials = (line.serials || '').toString().split(/\s+/).filter(Boolean);
                        cart.push({
                            product_id: parseInt(line.product_id, 10),
                            variant_id: line.variant_id ? parseInt(line.variant_id, 10) : null,
                            sku: '',
                            name: 'Line',
                            variant_name: '',
                            is_serialized: serials.length > 0,
                            gst_percentage: parseFloat(line.gst_percentage || '18') || 18,
                            unit_price: parseFloat(line.unit_price || '0') || 0,
                            qty: parseInt(line.qty, 10) || 1,
                            discount: parseFloat(line.discount || '0') || 0,
                            serials: serials,
                        });
                    });
                }
                renderCart();
            })();
        </script>
    @endif
@endpush
