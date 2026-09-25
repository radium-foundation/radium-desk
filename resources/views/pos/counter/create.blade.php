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
                                <div id="pos-serial-selected-wrap" hidden>
                                    <div class="small fw-semibold mb-2" id="pos-serial-selected-heading">Selected serials (0)</div>
                                    <div id="pos-serial-selected" class="d-flex flex-wrap gap-2 mb-3"></div>
                                </div>
                                <label class="form-label" for="pos-serial-entry">Add serials</label>
                                <input type="text" id="pos-serial-entry" class="form-control" placeholder="Scan or paste serials..." autocomplete="off">
                                <div id="pos-serial-feedback" class="small mt-2" hidden></div>
                                <label class="form-label mt-3" for="pos-serial-filter">Browse available</label>
                                <input type="search" id="pos-serial-filter" class="form-control form-control-sm" placeholder="Filter available serials..." autocomplete="off">
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
                                @include('pos.partials.customer-identity-conflict')
                                <div class="mb-2">
                                    <label class="form-label" for="customer_phone">Phone</label>
                                    <input type="text" name="customer_phone" id="customer_phone" class="form-control" required value="{{ old('customer_phone') }}" autocomplete="off">
                                    @error('customer_phone')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_name">Name</label>
                                    <input type="text" name="customer_name" id="customer_name" class="form-control" required value="{{ old('customer_name') }}" autocomplete="off">
                                    @error('customer_name')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="customer_email">Email</label>
                                    <input type="email" name="customer_email" id="customer_email" class="form-control" value="{{ old('customer_email') }}">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="buyer_gstin">Buyer GSTIN</label>
                                    <input type="text" name="buyer_gstin" id="buyer_gstin" class="form-control" value="{{ old('buyer_gstin') }}" maxlength="32" autocomplete="off" placeholder="Optional — leave blank for B2C">
                                    @error('buyer_gstin')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_address">Billing address</label>
                                    <textarea name="billing_address" id="billing_address" class="form-control" rows="2" maxlength="1000">{{ old('billing_address') }}</textarea>
                                    @error('billing_address')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_city">Billing city</label>
                                    <input type="text" name="billing_city" id="billing_city" class="form-control" value="{{ old('billing_city') }}" maxlength="120" autocomplete="off">
                                    @error('billing_city')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_state">Billing state</label>
                                    <select name="billing_state" id="billing_state" class="form-select">
                                        <option value="">Select billing state (required for B2B)</option>
                                        @foreach($placeOfSupplyStates as $state)
                                            <option value="{{ $state }}" @selected(old('billing_state') === $state)>{{ $state }}</option>
                                        @endforeach
                                    </select>
                                    @error('billing_state')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-2">
                                    <label class="form-label" for="billing_pincode">Billing PIN</label>
                                    <input type="text" name="billing_pincode" id="billing_pincode" class="form-control" value="{{ old('billing_pincode') }}" maxlength="6" inputmode="numeric" autocomplete="off">
                                    @error('billing_pincode')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div>
                                    <label class="form-label" for="place_of_supply_state">Place of supply</label>
                                    <select name="place_of_supply_state" id="place_of_supply_state" class="form-select">
                                        <option value="">Select state (required later for GST invoice)</option>
                                        @foreach($placeOfSupplyStates as $state)
                                            <option value="{{ $state }}" @selected(old('place_of_supply_state') === $state)>{{ $state }}</option>
                                        @endforeach
                                    </select>
                                    @error('place_of_supply_state')<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <p class="small text-muted mb-0 mt-2">Search by name, phone, or email. These values are stored on the sale for Finance Hub. Completing the sale does not issue a GST invoice.</p>
                                <div id="pos-customer-results" class="list-group mt-2 d-none pos-customer-lookup-results"></div>
                                <p class="small text-muted mb-0 mt-2" id="pos-customer-status"></p>
                                <div id="pos-customer-lookup-root" class="d-none" data-config='@json($customerLookupConfig)'></div>
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
                                <div class="mb-2">
                                    <label class="form-label" for="shipping_amount">Shipping (pre-tax)</label>
                                    <input type="number" step="0.01" min="0" name="shipping_amount" id="shipping_amount" class="form-control" value="{{ old('shipping_amount', 0) }}">
                                    @error('shipping_amount')<div class="text-danger small">{{ $message }}</div>@enderror
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
                                <div class="d-flex justify-content-between"><span>Shipping</span><span id="pos-shipping">0.00</span></div>
                                <div class="d-flex justify-content-between"><span>Tax</span><span id="pos-tax">0.00</span></div>
                                <div class="d-flex justify-content-between fw-semibold fs-5 mt-2"><span>Total</span><span id="pos-total">0.00</span></div>
                                <p class="small text-muted mb-0 mt-2">Internal invoice only — not a GST e-invoice.</p>
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

@push('styles')
    @if($operatingBranch)
        <style>
            .pos-customer-lookup-results {
                position: relative;
                z-index: 20;
                max-height: 16rem;
                overflow-y: auto;
            }
        </style>
    @endif
@endpush

@push('scripts')
    @if($operatingBranch)
        @vite('resources/js/pages/pos-customer-lookup-bootstrap.js')
        <script>
            (function () {
                const branchId = @json($operatingBranch->id);
                const productSearchUrl = @json($searchProductsUrl);
                const serialSearchUrl = @json($searchSerialsUrl);
                const matchSerialsUrl = @json($matchSerialsUrl);
                const oldLines = @json(array_values(old('lines', [])));

                const productInput = document.getElementById('pos-product-search');
                const productResults = document.getElementById('pos-product-results');
                const serialCard = document.getElementById('pos-serial-card');
                const serialHeading = document.getElementById('pos-serial-heading');
                const serialEntry = document.getElementById('pos-serial-entry');
                const serialFilter = document.getElementById('pos-serial-filter');
                const serialResults = document.getElementById('pos-serial-results');
                const serialSelectedWrap = document.getElementById('pos-serial-selected-wrap');
                const serialSelectedHeading = document.getElementById('pos-serial-selected-heading');
                const serialSelected = document.getElementById('pos-serial-selected');
                const serialFeedback = document.getElementById('pos-serial-feedback');
                const cartBody = document.getElementById('pos-cart-body');
                const cartEmpty = document.getElementById('pos-cart-empty');
                const cartFields = document.getElementById('pos-cart-fields');
                const headerDiscount = document.getElementById('discount');
                const shippingAmount = document.getElementById('shipping_amount');
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

                const buyerGstin = document.getElementById('buyer_gstin');
                const billingState = document.getElementById('billing_state');
                const placeOfSupply = document.getElementById('place_of_supply_state');

                function syncBillingStateFromPlaceOfSupply() {
                    if (!buyerGstin || !billingState || !placeOfSupply) {
                        return;
                    }
                    if ((buyerGstin.value || '').trim() === '') {
                        return;
                    }
                    if ((billingState.value || '').trim() !== '') {
                        return;
                    }
                    if ((placeOfSupply.value || '').trim() !== '') {
                        billingState.value = placeOfSupply.value;
                    }
                }

                if (placeOfSupply) {
                    placeOfSupply.addEventListener('change', syncBillingStateFromPlaceOfSupply);
                }
                if (buyerGstin) {
                    buyerGstin.addEventListener('input', syncBillingStateFromPlaceOfSupply);
                }

                let cart = [];
                let pendingProduct = null;
                let searchTimer = null;
                let serialFilterTimer = null;
                let submitting = false;
                let serialProcessing = false;

                function normalizeSerial(value) {
                    return (value || '').trim().toUpperCase();
                }

                function parseSerialList(value) {
                    if (!value) {
                        return [];
                    }

                    return value
                        .split(/[\s,;]+/)
                        .map(normalizeSerial)
                        .filter(Boolean)
                        .filter(function (serial, index, list) {
                            return list.indexOf(serial) === index;
                        });
                }

                function escapeHtml(value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;');
                }

                function cartHasSerial(serialNumber) {
                    return cart.some(function (item) {
                        return item.serials.indexOf(serialNumber) !== -1;
                    });
                }

                function pendingCartItem() {
                    if (!pendingProduct) {
                        return null;
                    }

                    const variantId = pendingProduct.variant ? pendingProduct.variant.id : null;

                    return cart.find(function (item) {
                        return item.is_serialized
                            && item.product_id === pendingProduct.product.id
                            && item.variant_id === variantId;
                    }) || null;
                }

                function pendingSerials() {
                    const item = pendingCartItem();

                    return item ? item.serials.slice() : [];
                }

                function showSerialFeedback(kind, lines) {
                    if (!serialFeedback) {
                        return;
                    }

                    if (!lines.length) {
                        serialFeedback.hidden = true;
                        serialFeedback.innerHTML = '';

                        return;
                    }

                    const className = kind === 'success' ? 'text-success' : 'text-danger';
                    serialFeedback.hidden = false;
                    serialFeedback.className = 'small mt-2 ' + className;
                    serialFeedback.innerHTML = lines.map(function (line) {
                        return '<div>' + escapeHtml(line) + '</div>';
                    }).join('');
                }

                function renderPendingSerials() {
                    if (!serialSelected || !serialSelectedWrap || !serialSelectedHeading) {
                        return;
                    }

                    const serials = pendingSerials();
                    serialSelected.innerHTML = '';
                    serialSelectedHeading.textContent = 'Selected serials (' + serials.length + ')';
                    serialSelectedWrap.hidden = serials.length === 0;

                    serials.forEach(function (serialNumber) {
                        const chip = document.createElement('span');
                        chip.className = 'badge text-bg-light border d-inline-flex align-items-center gap-2 py-2 px-2';
                        chip.innerHTML = '<span class="font-monospace">' + escapeHtml(serialNumber) + '</span>'
                            + '<button type="button" class="btn-close btn-close-sm" aria-label="Remove serial"></button>';
                        chip.querySelector('button').addEventListener('click', function () {
                            removePendingSerial(serialNumber);
                        });
                        serialSelected.appendChild(chip);
                    });
                }

                function removePendingSerial(serialNumber) {
                    const variantId = pendingProduct && pendingProduct.variant ? pendingProduct.variant.id : null;
                    const index = cart.findIndex(function (item) {
                        return item.is_serialized
                            && item.product_id === (pendingProduct ? pendingProduct.product.id : null)
                            && item.variant_id === variantId;
                    });

                    if (index === -1) {
                        return;
                    }

                    const item = cart[index];
                    item.serials = item.serials.filter(function (serial) {
                        return serial !== serialNumber;
                    });

                    if (item.serials.length === 0) {
                        cart.splice(index, 1);
                    } else {
                        item.qty = item.serials.length;
                    }

                    renderCart();
                    renderPendingSerials();
                    loadSerials(serialFilter ? serialFilter.value.trim() : '');
                }

                function money(value) {
                    return (Math.round(value * 100) / 100).toFixed(2);
                }

                function lineTotals(item) {
                    const lineSubtotal = item.unit_price * item.qty;
                    const taxable = Math.max(0, lineSubtotal - item.discount);
                    const tax = taxable * (item.gst_percentage / 100);
                    return { lineSubtotal, tax, lineTotal: taxable + tax };
                }

                function maxLineGstRate() {
                    let maxRate = 0;
                    cart.forEach(function (item) {
                        maxRate = Math.max(maxRate, parseFloat(item.gst_percentage) || 0);
                    });

                    return maxRate;
                }

                function shippingTaxAmount(shipping) {
                    const rate = maxLineGstRate();
                    if (shipping <= 0 || rate <= 0) {
                        return 0;
                    }

                    return shipping * (rate / 100);
                }

                function serializedCartKey(item) {
                    return item.product_id + ':' + (item.variant_id || 0);
                }

                function consolidateSerializedCart() {
                    const consolidated = [];
                    const indexByKey = {};

                    cart.forEach(function (item) {
                        if (!item.is_serialized) {
                            consolidated.push(item);

                            return;
                        }

                        const key = serializedCartKey(item);
                        if (indexByKey[key] === undefined) {
                            indexByKey[key] = consolidated.length;
                            consolidated.push(item);

                            return;
                        }

                        const existing = consolidated[indexByKey[key]];
                        item.serials.forEach(function (serial) {
                            if (existing.serials.indexOf(serial) === -1) {
                                existing.serials.push(serial);
                            }
                        });
                        existing.qty = existing.serials.length;
                    });

                    cart = consolidated;
                }

                function renderCart() {
                    consolidateSerializedCart();
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
                    renderPendingSerials();
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
                    const shipping = parseFloat(shippingAmount.value || '0') || 0;
                    const discount = header + lineDiscount;
                    const shippingTax = shippingTaxAmount(shipping);
                    const total = subtotal - discount + shipping + tax + shippingTax;
                    document.getElementById('pos-subtotal').textContent = money(subtotal);
                    document.getElementById('pos-discount').textContent = money(discount);
                    document.getElementById('pos-shipping').textContent = money(shipping);
                    document.getElementById('pos-tax').textContent = money(tax + shippingTax);
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
                    const normalized = normalizeSerial(serialNumber);
                    if (normalized === '') {
                        return { added: false, reason: 'empty' };
                    }

                    const variantId = variant ? variant.id : null;
                    if (cartHasSerial(normalized)) {
                        return { added: false, reason: 'duplicate', serial: normalized };
                    }

                    const existing = cart.find(function (item) {
                        return item.is_serialized
                            && item.product_id === product.id
                            && item.variant_id === variantId;
                    });

                    if (existing) {
                        existing.serials.push(normalized);
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
                            serials: [normalized],
                        });
                    }

                    renderCart();

                    return { added: true, serial: normalized };
                }

                function matchSerialTokens(tokens) {
                    if (!pendingProduct || !tokens.length) {
                        return Promise.resolve({ results: [] });
                    }

                    const params = new URLSearchParams({
                        branch_id: String(branchId),
                        product_id: String(pendingProduct.product.id),
                        serials: tokens.join('\n'),
                    });
                    if (pendingProduct.variant) {
                        params.set('variant_id', String(pendingProduct.variant.id));
                    }

                    return fetch(matchSerialsUrl + '?' + params.toString(), {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    }).then(function (response) {
                        if (!response.ok) {
                            throw new Error('serial-match-failed');
                        }

                        return response.json();
                    });
                }

                function processSerialTokens(tokens) {
                    if (!pendingProduct || !tokens.length || serialProcessing) {
                        return Promise.resolve();
                    }

                    serialProcessing = true;
                    const alreadySelected = [];
                    const toValidate = [];

                    tokens.forEach(function (token) {
                        const normalized = normalizeSerial(token);
                        if (normalized === '') {
                            return;
                        }
                        if (cartHasSerial(normalized)) {
                            alreadySelected.push(normalized);

                            return;
                        }
                        toValidate.push(normalized);
                    });

                    if (!toValidate.length) {
                        const lines = [];
                        if (alreadySelected.length) {
                            lines.push('Already selected: ' + alreadySelected.join(', '));
                        }
                        showSerialFeedback(alreadySelected.length ? 'error' : 'success', lines);
                        serialProcessing = false;

                        return Promise.resolve();
                    }

                    return matchSerialTokens(toValidate)
                        .then(function (data) {
                            const added = [];
                            const unavailable = [];
                            const notFound = [];
                            const wrongBranch = [];

                            (data.results || []).forEach(function (result) {
                                const serialNumber = result.serial_number || result.input;
                                if (result.status === 'available') {
                                    const outcome = addSerializedItem(
                                        pendingProduct.product,
                                        pendingProduct.variant,
                                        serialNumber,
                                    );
                                    if (outcome.added) {
                                        added.push(outcome.serial);
                                    } else if (outcome.reason === 'duplicate') {
                                        alreadySelected.push(outcome.serial);
                                    }

                                    return;
                                }

                                if (result.status === 'wrong_branch') {
                                    wrongBranch.push(serialNumber);

                                    return;
                                }

                                if (result.status === 'not_found' || result.status === 'wrong_product' || result.status === 'wrong_variant') {
                                    notFound.push(serialNumber);

                                    return;
                                }

                                unavailable.push(serialNumber);
                            });

                            const lines = [];
                            if (added.length) {
                                lines.push('✓ ' + added.length + ' serial' + (added.length === 1 ? '' : 's') + ' added');
                            }
                            if (alreadySelected.length) {
                                lines.push('Already selected: ' + alreadySelected.join(', '));
                            }
                            if (unavailable.length) {
                                lines.push('Unavailable: ' + unavailable.join(', '));
                            }
                            if (notFound.length) {
                                lines.push('Not found for this product: ' + notFound.join(', '));
                            }
                            if (wrongBranch.length) {
                                lines.push('Not available at this branch: ' + wrongBranch.join(', '));
                            }

                            showSerialFeedback(added.length ? 'success' : 'error', lines);
                            loadSerials(serialFilter ? serialFilter.value.trim() : '');
                        })
                        .catch(function () {
                            showSerialFeedback('error', ['Could not validate serials. Try again.']);
                        })
                        .finally(function () {
                            serialProcessing = false;
                        });
                }

                function submitSerialEntry(rawValue) {
                    const tokens = parseSerialList(rawValue);
                    if (!tokens.length) {
                        return Promise.resolve();
                    }

                    if (serialEntry) {
                        serialEntry.value = '';
                    }

                    return processSerialTokens(tokens);
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
                                    if (serialEntry) {
                                        serialEntry.value = '';
                                    }
                                    if (serialFilter) {
                                        serialFilter.value = '';
                                    }
                                    showSerialFeedback('success', []);
                                    renderPendingSerials();
                                    if (serialEntry) {
                                        serialEntry.focus();
                                    }
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
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error('serial-search-failed');
                            }
                            return response.json();
                        })
                        .then(function (data) {
                            serialResults.innerHTML = '';
                            (data.serials || []).forEach(function (serial) {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.textContent = serial.serial_number;
                                button.addEventListener('click', function () {
                                    processSerialTokens([serial.serial_number]);
                                });
                                serialResults.appendChild(button);
                            });
                            if (!(data.serials || []).length) {
                                serialResults.innerHTML = '<div class="list-group-item text-muted">No available serials at this branch.</div>';
                            }
                        })
                        .catch(function () {
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

                if (serialEntry) {
                    serialEntry.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter' || event.key === 'Tab') {
                            event.preventDefault();
                            submitSerialEntry(serialEntry.value);
                        }
                    });

                    serialEntry.addEventListener('paste', function (event) {
                        const pasted = (event.clipboardData || window.clipboardData).getData('text');
                        if (!pasted || !parseSerialList(pasted).length) {
                            return;
                        }

                        event.preventDefault();
                        serialEntry.value = '';
                        submitSerialEntry(pasted);
                    });
                }

                if (serialFilter) {
                    serialFilter.addEventListener('input', function () {
                        clearTimeout(serialFilterTimer);
                        serialFilterTimer = setTimeout(function () {
                            loadSerials(serialFilter.value.trim());
                        }, 200);
                    });
                }

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
                shippingAmount.addEventListener('input', renderTotals);

                if (completeButton) {
                    completeButton.addEventListener('click', function (event) {
                        if (submitting || form.dataset.submitting === '1') {
                            event.preventDefault();
                            event.stopImmediatePropagation();
                        }
                    }, true);
                }

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
                        const serials = parseSerialList((line.serials || '').toString());
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
