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
                                <input type="text" id="pos-serial-search" class="form-control" placeholder="Scan barcode/QR or type serial, then Enter" autocomplete="off" spellcheck="false" enterkeyhint="done">
                                <p class="small mb-0 mt-2" id="pos-serial-status"></p>
                                <div id="pos-serial-results" class="list-group mt-2"></div>
                                <p class="small text-muted mb-0 mt-2">Keep this field focused. Scan each serial — Enter adds it. One cart row; Qty = unique serials. Sold, reserved, duplicate, and wrong-SKU serials are rejected.</p>
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
                                <p class="small text-muted mb-2">Type a phone or name to find an existing customer, then click a match. Typing does not select automatically.</p>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_phone">Phone</label>
                                    <input type="text" name="customer_phone" id="customer_phone" class="form-control" required value="{{ old('customer_phone') }}" autocomplete="off" placeholder="Search existing customer…">
                                    @error('customer_phone')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_name">Name</label>
                                    <input type="text" name="customer_name" id="customer_name" class="form-control" required value="{{ old('customer_name') }}" autocomplete="off" placeholder="Search existing customer…">
                                    @error('customer_name')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div id="pos-customer-results" class="list-group mb-2 d-none" style="max-height: 16rem; overflow-y: auto;"></div>
                                <p class="small text-muted mb-2" id="pos-customer-status"></p>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_email">Email</label>
                                    <input type="email" name="customer_email" id="customer_email" class="form-control" value="{{ old('customer_email') }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="buyer_gstin">Buyer GSTIN</label>
                                    <input type="text" name="buyer_gstin" id="buyer_gstin" class="form-control" value="{{ old('buyer_gstin') }}" maxlength="32" autocomplete="off" spellcheck="false" placeholder="Optional — leave blank for B2C">
                                    <p class="small text-muted mb-0 mt-1" id="pos-gstin-source"></p>
                                    @error('buyer_gstin')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_address">Billing address</label>
                                    <textarea name="billing_address" id="billing_address" class="form-control" rows="2" maxlength="1000">{{ old('billing_address') }}</textarea>
                                    @error('billing_address')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_city">City</label>
                                    <input type="text" name="billing_city" id="billing_city" class="form-control" value="{{ old('billing_city') }}" maxlength="128" autocomplete="address-level2">
                                    @error('billing_city')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_state">Billing state</label>
                                    <select name="billing_state" id="billing_state" class="form-select">
                                        <option value="">Select state</option>
                                        @foreach($placeOfSupplyStates as $state)
                                            <option value="{{ $state }}" @selected(old('billing_state') === $state)>{{ $state }}</option>
                                        @endforeach
                                    </select>
                                    @error('billing_state')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_pincode">PIN</label>
                                    <input type="text" name="billing_pincode" id="billing_pincode" class="form-control" value="{{ old('billing_pincode') }}" maxlength="6" inputmode="numeric" autocomplete="postal-code">
                                    @error('billing_pincode')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="form-label" for="place_of_supply_state">Place of supply</label>
                                    <select name="place_of_supply_state" id="place_of_supply_state" class="form-select">
                                        <option value="">Use branch default for B2C</option>
                                        @foreach($placeOfSupplyStates as $state)
                                            <option value="{{ $state }}" @selected(old('place_of_supply_state', $defaultPlaceOfSupplyState) === $state)>{{ $state }}</option>
                                        @endforeach
                                    </select>
                                    @error('place_of_supply_state')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <p class="small text-muted mb-0 mt-2">These values are snapshotted on the sale. A GST tax invoice is issued automatically after a successful sale when statutory data is complete. B2C walk-in sales default place of supply to the selling branch state. City, state, and PIN are required when a GSTIN is entered.</p>
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
                                    <p class="small text-muted mb-0 mt-1">Completing a sale records this method as how the sale was settled. Bank Transfer does not by itself prove the money has arrived — enter a reference when you have one. UPI stays unpaid until the UTR is verified.</p>
                                </div>
                                <div class="mb-2" id="upi-receiving-account-wrap" hidden>
                                    <label class="form-label" for="receiving_bank_account_id">Receiving bank account</label>
                                    <select name="receiving_bank_account_id" id="receiving_bank_account_id" class="form-select">
                                        <option value="">Select account</option>
                                        @foreach($upiReceivingAccounts as $account)
                                            <option value="{{ $account->id }}" @selected((string) old('receiving_bank_account_id') === (string) $account->id)>
                                                {{ $account->bank_name }} · {{ $account->last_four }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <p class="small text-muted mb-0 mt-1">UPI creates an unpaid intent and a local QR. It does not complete the sale.</p>
                                    @if($upiReceivingAccounts->isEmpty())
                                        <p class="small text-danger mb-0">No UPI-enabled receiving accounts are configured yet.</p>
                                    @endif
                                    @error('receiving_bank_account_id')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2" id="payment-reference-wrap">
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
                                <div class="d-flex justify-content-between"><span>Round Off</span><span id="pos-roundoff">0.00</span></div>
                                <div class="d-flex justify-content-between fw-semibold fs-5 mt-2"><span>Total</span><span id="pos-total">0.00</span></div>
                                <p class="small text-muted mb-0 mt-2">GST is unchanged. Round Off is nearest rupee. Internal receipt is not a GST e-invoice.</p>
                            </div>
                        </div>

                        <button class="btn btn-primary w-100" id="pos-complete" type="submit">Complete sale</button>
                        <a href="{{ route('pos.sales.index') }}" class="btn btn-outline-secondary w-100 mt-2">Sale history</a>
                        <a href="{{ route('pos.upi.intents.index') }}" class="btn btn-outline-secondary w-100 mt-2">Recover pending UPI</a>
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
                const matchSerialUrl = @json($matchSerialUrl);
                const searchCustomersUrl = @json($searchCustomersUrl);
                const showCustomerUrlTemplate = @json($showCustomerUrl);
                const defaultPlaceOfSupplyState = @json($defaultPlaceOfSupplyState);
                const oldLines = @json(array_values(old('lines', [])));

                const productInput = document.getElementById('pos-product-search');
                const productResults = document.getElementById('pos-product-results');
                const serialCard = document.getElementById('pos-serial-card');
                const serialHeading = document.getElementById('pos-serial-heading');
                const serialInput = document.getElementById('pos-serial-search');
                const serialResults = document.getElementById('pos-serial-results');
                const serialStatus = document.getElementById('pos-serial-status');
                const gstinSource = document.getElementById('pos-gstin-source');
                const cartBody = document.getElementById('pos-cart-body');
                const cartEmpty = document.getElementById('pos-cart-empty');
                const cartFields = document.getElementById('pos-cart-fields');
                const headerDiscount = document.getElementById('discount');
                const phoneInput = document.getElementById('customer_phone');
                const nameInput = document.getElementById('customer_name');
                const emailInput = document.getElementById('customer_email');
                const gstinInput = document.getElementById('buyer_gstin');
                const billingAddressInput = document.getElementById('billing_address');
                const billingCity = document.getElementById('billing_city');
                const billingState = document.getElementById('billing_state');
                const billingPincode = document.getElementById('billing_pincode');
                const customerResults = document.getElementById('pos-customer-results');
                const customerStatus = document.getElementById('pos-customer-status');
                const placeOfSupplyState = document.getElementById('place_of_supply_state');
                const form = document.getElementById('pos-counter-form');
                const completeButton = document.getElementById('pos-complete');
                const paymentMethod = document.getElementById('payment_method');
                const upiAccountWrap = document.getElementById('upi-receiving-account-wrap');
                const upiAccount = document.getElementById('receiving_bank_account_id');
                const paymentReferenceWrap = document.getElementById('payment-reference-wrap');

                function syncPaymentMethod() {
                    const isUpi = (paymentMethod.value || '').toUpperCase() === 'UPI';
                    upiAccountWrap.hidden = !isUpi;
                    upiAccount.required = isUpi;
                    paymentReferenceWrap.hidden = isUpi;
                    completeButton.textContent = isUpi ? 'Create UPI QR' : 'Complete sale';
                }
                paymentMethod.addEventListener('change', syncPaymentMethod);
                syncPaymentMethod();

                function syncB2bAddressFields() {
                    const required = !!(gstinInput && gstinInput.value && gstinInput.value.trim() !== '');
                    [billingCity, billingState, billingPincode].forEach(function (el) {
                        if (el) {
                            el.required = required;
                        }
                    });
                }
                if (gstinInput) {
                    gstinInput.addEventListener('input', function () {
                        if (gstinSource && !gstinInput.value.trim()) {
                            gstinSource.textContent = 'GSTIN cleared — this sale will be B2C unless a GSTIN is entered.';
                        }
                        syncB2bAddressFields();
                    });
                    gstinInput.addEventListener('change', syncB2bAddressFields);
                }
                syncB2bAddressFields();

                let cart = [];
                let pendingProduct = null;
                let searchTimer = null;
                let serialTimer = null;
                let phoneTimer = null;
                let nameTimer = null;
                let submitting = false;
                let selectedCustomerId = null;
                let serialBusy = false;
                let serialQueue = [];
                let serialSearchGeneration = 0;
                let serialListRefreshTimer = null;

                function money(value) {
                    return (Math.round(value * 100) / 100).toFixed(2);
                }

                function lineTotals(item) {
                    const lineSubtotal = item.unit_price * item.qty;
                    const taxable = Math.max(0, lineSubtotal - item.discount);
                    const tax = taxable * (item.gst_percentage / 100);
                    return { lineSubtotal, taxable, tax, lineTotal: taxable + tax };
                }

                function renderCart() {
                    cartBody.querySelectorAll('tr.pos-cart-row').forEach(function (row) { row.remove(); });
                    cartEmpty.hidden = cart.length > 0;
                    cart.forEach(function (item, index) {
                        const totals = lineTotals(item);
                        const row = document.createElement('tr');
                        row.className = 'pos-cart-row';
                        const serialNote = item.serials.length
                            ? '<div class="small text-muted d-flex flex-wrap gap-1 mt-1">' + item.serials.map(function (serial) {
                                return '<button type="button" class="btn btn-sm btn-outline-secondary py-0 pos-serial-remove" data-index="' + index + '" data-serial="' + serial + '">' + serial + ' ×</button>';
                            }).join('') + '</div>'
                            : '';
                        const variantNote = item.variant_name ? ' · ' + item.variant_name : '';
                        row.innerHTML =
                            '<td>' + item.sku + ' — ' + item.name + variantNote + serialNote + '</td>' +
                            '<td class="text-end"><input type="number" min="1" class="form-control form-control-sm text-end pos-qty" data-index="' + index + '" value="' + item.qty + '"' + (item.is_serialized ? ' readonly' : '') + '></td>' +
                            '<td class="text-end"><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pos-price" data-index="' + index + '" value="' + money(item.unit_price) + '"></td>' +
                            '<td class="text-end"><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end pos-line-discount" data-index="' + index + '" value="' + money(item.discount) + '"></td>' +
                            '<td class="text-end pos-line-amount">' + money(totals.taxable) + '</td>' +
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
                    const unrounded = Math.round(Math.max(0, total) * 100) / 100;
                    const rounded = Math.round(unrounded);
                    const roundOff = Math.round((rounded - unrounded) * 100) / 100;
                    document.getElementById('pos-subtotal').textContent = money(subtotal);
                    document.getElementById('pos-discount').textContent = money(discount);
                    document.getElementById('pos-tax').textContent = money(tax);
                    const roundEl = document.getElementById('pos-roundoff');
                    if (roundEl) {
                        roundEl.textContent = money(roundOff);
                    }
                    document.getElementById('pos-total').textContent = money(rounded);
                }

                function paintLineCell(index) {
                    const row = cartBody.querySelectorAll('tr.pos-cart-row')[index];
                    if (!row || !cart[index]) {
                        return;
                    }
                    const cell = row.querySelector('td.pos-line-amount');
                    if (cell) {
                        cell.textContent = money(lineTotals(cart[index]).taxable);
                    }
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

                function setSerialStatus(message, ok) {
                    if (!serialStatus) {
                        return;
                    }
                    serialStatus.textContent = message || '';
                    serialStatus.className = 'small mb-0 mt-2 ' + (ok === true ? 'text-success' : (ok === false ? 'text-danger' : 'text-muted'));
                }

                function addSerializedItem(product, variant, serialNumber) {
                    const variantId = variant ? variant.id : null;
                    if (cart.some(function (item) { return item.serials.indexOf(serialNumber) !== -1; })) {
                        setSerialStatus('Duplicate: ' + serialNumber + ' is already in the cart.', false);
                        return false;
                    }
                    const existing = cart.find(function (item) {
                        return item.product_id === product.id && item.variant_id === variantId && item.is_serialized;
                    });
                    if (existing) {
                        existing.serials.push(serialNumber);
                        existing.qty = existing.serials.length;
                    } else {
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
                    }
                    renderCart();
                    setSerialStatus('Added ' + serialNumber + ' · Qty ' + (existing ? existing.qty : 1) + '.', true);
                    return true;
                }

                function removeSerializedUnit(cartIndex, serialNumber) {
                    const item = cart[cartIndex];
                    if (!item || !item.is_serialized) {
                        return;
                    }
                    item.serials = item.serials.filter(function (value) { return value !== serialNumber; });
                    if (item.serials.length === 0) {
                        cart.splice(cartIndex, 1);
                    } else {
                        item.qty = item.serials.length;
                    }
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
                                    setSerialStatus('Ready to scan. Enter adds the serial.', null);
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
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('product-search-failed');
                            }
                            return response.json();
                        })
                        .then(function (data) { showProductResults(data.products || []); })
                        .catch(function () {
                            productResults.innerHTML = '<div class="list-group-item text-danger">Could not search products. Try again.</div>';
                            productResults.classList.remove('d-none');
                        });
                }

                function scheduleSerialListRefresh() {
                    if (serialBusy || serialQueue.length > 0 || !pendingProduct) {
                        return;
                    }
                    clearTimeout(serialListRefreshTimer);
                    serialListRefreshTimer = setTimeout(function () {
                        loadSerials(serialInput.value.trim());
                    }, 400);
                }

                function loadSerials(query) {
                    if (!pendingProduct || serialBusy || serialQueue.length > 0) {
                        return;
                    }
                    const generation = ++serialSearchGeneration;
                    const params = new URLSearchParams({
                        branch_id: String(branchId),
                        q: query,
                        product_id: String(pendingProduct.product.id),
                    });
                    if (pendingProduct.variant) {
                        params.set('variant_id', String(pendingProduct.variant.id));
                    }
                    fetch(serialSearchUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) {
                            if (generation !== serialSearchGeneration) {
                                return null;
                            }
                            if (response.status === 429) {
                                throw new Error('serial-search-rate-limited');
                            }
                            if (!response.ok) {
                                throw new Error('serial-search-failed');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            if (data === null || generation !== serialSearchGeneration) {
                                return;
                            }
                            serialResults.innerHTML = '';
                            (data.serials || []).forEach(function (serial) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.textContent = serial.serial_number;
                                button.addEventListener('click', function () {
                                    enqueueScan(serial.serial_number);
                                });
                                serialResults.appendChild(button);
                            });
                            if (!(data.serials || []).length) {
                                serialResults.innerHTML = '<div class="list-group-item text-muted">No available serials at this branch.</div>';
                            }
                        })
                        .catch(function (error) {
                            if (generation !== serialSearchGeneration) {
                                return;
                            }
                            if (error && error.message === 'serial-search-rate-limited') {
                                serialResults.innerHTML = '<div class="list-group-item text-warning">Serial list paused — server rate limit. Scanning can continue.</div>';
                                return;
                            }
                            serialResults.innerHTML = '<div class="list-group-item text-danger">Could not search serials. Try again.</div>';
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

                function normalizeScan(raw) {
                    return (raw || '').replace(/[\r\n]+/g, '').trim();
                }

                function serialAlreadyInCart(query) {
                    const upper = query.toUpperCase();
                    return cart.some(function (item) {
                        return item.serials.some(function (serial) {
                            return serial === query || serial.toUpperCase() === upper;
                        });
                    });
                }

                function finishSerialMatchAttempt() {
                    serialBusy = false;
                    serialInput.value = '';
                    serialInput.focus();
                    drainSerialQueue();
                }

                function drainSerialQueue() {
                    if (serialBusy || serialQueue.length === 0) {
                        scheduleSerialListRefresh();
                        return;
                    }
                    if (!pendingProduct) {
                        serialQueue = [];
                        setSerialStatus('Select a product before scanning serials.', false);
                        return;
                    }

                    serialBusy = true;
                    clearTimeout(serialTimer);
                    serialSearchGeneration += 1;
                    const query = serialQueue.shift();
                    const queuedNote = serialQueue.length ? ' · ' + serialQueue.length + ' queued' : '';
                    setSerialStatus('Checking ' + query + queuedNote + '…', null);

                    const params = new URLSearchParams({
                        branch_id: String(branchId),
                        q: query,
                        product_id: String(pendingProduct.product.id),
                    });
                    if (pendingProduct.variant) {
                        params.set('variant_id', String(pendingProduct.variant.id));
                    }

                    fetch(matchSerialUrl + '?' + params.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) {
                            if (response.status === 429) {
                                serialQueue.unshift(query);
                                const retryAfter = parseInt(response.headers.get('Retry-After') || '1', 10);
                                const retryMs = Math.max(1000, (Number.isFinite(retryAfter) ? retryAfter : 1) * 1000);
                                setSerialStatus('Server rate limit — pausing scans for ' + Math.ceil(retryMs / 1000) + 's. Serials stay queued.', false);
                                return new Promise(function (resolve) {
                                    setTimeout(resolve, retryMs);
                                }).then(function () {
                                    return { rateLimited: true };
                                });
                            }
                            if (!response.ok) {
                                throw new Error('serial-match-failed');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            if (!data || data.rateLimited) {
                                return;
                            }
                            if (!data.ok) {
                                setSerialStatus((data && data.message) ? data.message : 'Serial was rejected.', false);
                                return;
                            }
                            const number = data.serial && data.serial.serial_number ? data.serial.serial_number : query;
                            addSerializedItem(pendingProduct.product, pendingProduct.variant, number);
                        })
                        .catch(function () {
                            setSerialStatus('Could not verify that serial. Try again.', false);
                        })
                        .finally(function () {
                            finishSerialMatchAttempt();
                        });
                }

                function enqueueScan(raw) {
                    if (!pendingProduct) {
                        setSerialStatus('Select a product before scanning serials.', false);
                        return;
                    }
                    const query = normalizeScan(raw || serialInput.value);
                    if (!query) {
                        return;
                    }
                    if (serialAlreadyInCart(query)) {
                        setSerialStatus('Duplicate: ' + query + ' is already in the cart.', false);
                        serialInput.value = '';
                        serialInput.focus();
                        return;
                    }
                    const upper = query.toUpperCase();
                    if (serialQueue.some(function (queued) { return queued.toUpperCase() === upper; })) {
                        setSerialStatus('Duplicate scan: ' + query + ' is already queued.', false);
                        serialInput.value = '';
                        serialInput.focus();
                        return;
                    }
                    serialQueue.push(query);
                    serialInput.value = '';
                    drainSerialQueue();
                }

                serialInput.addEventListener('input', function () {
                    const value = serialInput.value;
                    if (/[\r\n]/.test(value)) {
                        enqueueScan(value);
                        return;
                    }
                    if (serialBusy || serialQueue.length > 0) {
                        return;
                    }
                    clearTimeout(serialTimer);
                    serialTimer = setTimeout(function () { loadSerials(value.trim()); }, 200);
                });

                serialInput.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter') {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    enqueueScan(serialInput.value);
                });

                cartBody.addEventListener('click', function (event) {
                    const serialButton = event.target.closest('.pos-serial-remove');
                    if (serialButton) {
                        removeSerializedUnit(
                            parseInt(serialButton.getAttribute('data-index'), 10),
                            serialButton.getAttribute('data-serial')
                        );
                        return;
                    }
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
                    paintLineCell(index);
                    syncFields();
                });

                headerDiscount.addEventListener('input', renderTotals);

                function setBuyerGstin(value) {
                    const normalized = (value || '').toString().replace(/\s+/g, '').toUpperCase();
                    if (gstinInput) {
                        gstinInput.value = normalized;
                        gstinInput.setAttribute('value', normalized);
                    }
                    if (gstinSource) {
                        gstinSource.textContent = normalized
                            ? 'Customer master GSTIN ' + normalized + ' — leave blank only for a B2C sale.'
                            : 'No GSTIN on the customer master. Leave blank for B2C or type a valid GSTIN.';
                    }
                    if (gstinInput) {
                        syncB2bAddressFields();
                    }
                }

                function applyCustomerPayload(data) {
                    if (!data || !data.found) {
                        return;
                    }
                    selectedCustomerId = data.id || null;
                    nameInput.value = data.name || '';
                    phoneInput.value = data.phone || '';
                    emailInput.value = data.email || '';
                    setBuyerGstin(data.gstin || '');
                    if (billingAddressInput) {
                        billingAddressInput.value = data.billing_address || '';
                    }
                    if (billingCity) {
                        billingCity.value = data.billing_city || '';
                    }
                    if (billingState) {
                        billingState.value = data.billing_state || '';
                    }
                    if (billingPincode) {
                        billingPincode.value = data.billing_pincode || '';
                    }
                    if (placeOfSupplyState) {
                        placeOfSupplyState.value = data.place_of_supply_state || defaultPlaceOfSupplyState || '';
                    }
                    customerResults.classList.add('d-none');
                    if (data.billing_source === 'last_sale_snapshot') {
                        customerStatus.textContent = 'Existing POS customer. Name, phone, email, and GSTIN are from the customer master. Address shown is last-sale billing, not a stored customer-master address. Review before completing.';
                    } else if (data.billing_source === 'imported_billing_profile') {
                        customerStatus.textContent = 'Existing POS customer. Name, phone, email, and GSTIN are from the customer master. Address shown is last invoiced historical POS billing — review before completing. This sale snapshots the form.';
                    } else {
                        customerStatus.textContent = 'Existing POS customer. Name, phone, email, and GSTIN are from the customer master. No stored billing address — leave blank for B2C or enter only known details. Place of supply defaults to the selling branch for B2C.';
                    }
                    window.setTimeout(function () { setBuyerGstin(data.gstin || ''); }, 50);
                }

                function showCustomerResults(customers) {
                    customerResults.innerHTML = '';
                    if (!customers.length) {
                        customerResults.classList.add('d-none');
                        customerStatus.textContent = 'No matching POS customers. A new customer will be created on complete.';
                        return;
                    }
                    customers.forEach(function (customer) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'list-group-item list-group-item-action';
                        const gstinNote = customer.gstin ? ' · GSTIN ' + customer.gstin : '';
                        button.textContent = customer.name + ' · ' + customer.phone + gstinNote;
                        button.addEventListener('click', function () {
                            setBuyerGstin(customer.gstin || '');
                            fetch(showCustomerUrlTemplate.replace('__ID__', String(customer.id)), { headers: { 'Accept': 'application/json' } })
                                .then(function (response) {
                                    if (!response.ok) {
                                        throw new Error('customer-show-failed');
                                    }
                                    return response.json();
                                })
                                .then(applyCustomerPayload)
                                .catch(function () {
                                    customerStatus.textContent = 'Could not load that customer. Try again.';
                                });
                        });
                        customerResults.appendChild(button);
                    });
                    customerResults.classList.remove('d-none');
                    customerStatus.textContent = customers.length === 1
                        ? '1 customer found. Click the match to fill this form.'
                        : customers.length + ' customers found. Click a match to fill this form.';
                }

                function searchCustomers(query) {
                    const trimmed = (query || '').trim();
                    if (trimmed.length < 2) {
                        customerResults.classList.add('d-none');
                        customerStatus.textContent = '';
                        selectedCustomerId = null;
                        return;
                    }
                    fetch(searchCustomersUrl + '?q=' + encodeURIComponent(trimmed), { headers: { 'Accept': 'application/json' } })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('customer-search-failed');
                            }
                            return response.json();
                        })
                        .then(function (data) { showCustomerResults(data.customers || []); })
                        .catch(function () {
                            customerStatus.textContent = 'Could not search customers. You can still complete the sale.';
                        });
                }

                function scheduleCustomerSearch(query, timerRef) {
                    clearTimeout(timerRef);
                    return setTimeout(function () { searchCustomers(query); }, 250);
                }

                phoneInput.addEventListener('input', function () {
                    phoneTimer = scheduleCustomerSearch(phoneInput.value.replace(/\s+/g, ''), phoneTimer);
                });

                nameInput.addEventListener('input', function () {
                    nameTimer = scheduleCustomerSearch(nameInput.value, nameTimer);
                });

                if (completeButton) {
                    completeButton.addEventListener('click', function (event) {
                        if (submitting || form.dataset.submitting === '1') {
                            event.preventDefault();
                            event.stopImmediatePropagation();
                        }
                    }, true);
                }

                form.addEventListener('keydown', function (event) {
                    if (event.key !== 'Enter') {
                        return;
                    }
                    const target = event.target;
                    if (!target || target === completeButton) {
                        return;
                    }
                    if (target.tagName === 'TEXTAREA') {
                        return;
                    }
                    event.preventDefault();
                    if (target === serialInput) {
                        captureScan(serialInput.value);
                    }
                });

                form.addEventListener('submit', function (event) {
                    syncFields();
                    if (!cart.length) {
                        event.preventDefault();
                        alert('Add at least one item to the cart.');
                        return;
                    }
                    if (submitting || form.dataset.submitting === '1') {
                        event.preventDefault();
                        return;
                    }
                    submitting = true;
                    form.dataset.submitting = '1';
                    if (completeButton) {
                        completeButton.disabled = true;
                        completeButton.setAttribute('aria-busy', 'true');
                        completeButton.textContent = 'Completing…';
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
