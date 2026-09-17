@extends('layouts.app')

@section('title', 'Service counter')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Service POS</p>
        <h1 class="h3 mb-1">Service counter</h1>
        <p class="text-muted mb-0">Create an internal proforma (quote). This is <strong>not</strong> a GST invoice.</p>
    </div>

    @include('service-pos.partials.workspace-nav')

    @if($branches->isEmpty())
        <div class="alert alert-secondary">No active branches available.</div>
    @else
        <form method="POST" action="{{ route('service-pos.quotes.store') }}" id="service-pos-form">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6">Add services</h2>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <select id="svc-category-filter" class="form-select">
                                        <option value="">All categories</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <input type="search" id="svc-item-search" class="form-control" placeholder="Search service code or name" autocomplete="off">
                                </div>
                            </div>
                            <div id="svc-search-results" class="list-group mb-3"></div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="svc-add-custom">Add custom line</button>
                            <div class="table-responsive mt-3">
                                <table class="table align-middle">
                                    <thead><tr><th>Service</th><th>SAC</th><th class="text-end">Qty</th><th class="text-end">Ex-GST</th><th class="text-end">GST%</th><th class="text-end">Line</th><th></th></tr></thead>
                                    <tbody id="svc-cart-body"></tbody>
                                </table>
                            </div>
                            <div id="svc-line-fields"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6">Customer</h2>
                            <div class="mb-2"><label class="form-label">Branch</label>
                                <select name="branch_id" class="form-select" required>
                                    @foreach($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected((int) ($operatingBranch?->id) === (int) $branch->id)>{{ $branch->code }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2"><label class="form-label" for="svc-customer-name">Name</label><input name="customer_name" id="svc-customer-name" class="form-control" required value="{{ old('customer_name') }}" autocomplete="off"></div>
                            <div class="mb-2"><label class="form-label" for="svc-customer-phone">Phone</label><input name="customer_phone" id="svc-customer-phone" class="form-control" required value="{{ old('customer_phone') }}" autocomplete="off"></div>
                            <div class="mb-2"><label class="form-label" for="svc-customer-email">Email</label><input name="customer_email" id="svc-customer-email" type="email" class="form-control" value="{{ old('customer_email') }}" autocomplete="off"></div>
                            <div class="mb-2"><label class="form-label" for="svc-buyer-gstin">GSTIN</label><input name="buyer_gstin" id="svc-buyer-gstin" class="form-control" value="{{ old('buyer_gstin') }}"></div>
                            <p class="small text-muted">Search by name, phone, or email to select an existing customer.</p>
                            <div id="svc-customer-results" class="list-group mb-2 d-none pos-customer-lookup-results"></div>
                            <p class="small text-muted mb-2" id="svc-customer-status"></p>
                            <div id="pos-customer-lookup-root" class="d-none" data-config='@json($customerLookupConfig)'></div>
                            <div class="mb-2"><label class="form-label" for="svc-billing-state">Billing state</label>
                                <select name="billing_state" id="svc-billing-state" class="form-select" required>
                                    @foreach($placeOfSupplyStates as $state)
                                        <option value="{{ $state }}" @selected(old('billing_state') === $state)>{{ $state }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2"><label class="form-label" for="svc-place-of-supply">Place of supply</label>
                                <select name="place_of_supply_state" id="svc-place-of-supply" class="form-select">
                                    <option value="">Same as billing</option>
                                    @foreach($placeOfSupplyStates as $state)
                                        <option value="{{ $state }}">{{ $state }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2"><label class="form-label" for="svc-billing-address">Billing address</label><textarea name="billing_address" id="svc-billing-address" class="form-control" rows="2">{{ old('billing_address') }}</textarea></div>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between"><span>Subtotal (ex-GST)</span><strong id="svc-subtotal">₹0.00</strong></div>
                            <div class="d-flex justify-content-between"><span>Tax</span><strong id="svc-tax">₹0.00</strong></div>
                            <div class="d-flex justify-content-between border-top pt-2 mt-2"><span>Total</span><strong id="svc-total">₹0.00</strong></div>
                            <button type="submit" class="btn btn-primary w-100 mt-3">Create internal proforma</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    @endif
@endsection

@push('styles')
    <style>
        .pos-customer-lookup-results {
            position: relative;
            z-index: 20;
            max-height: 16rem;
            overflow-y: auto;
        }
    </style>
@endpush

@push('scripts')
@vite('resources/js/pages/pos-customer-lookup-bootstrap.js')
<script>
(() => {
    const searchUrl = @json($searchItemsUrl);
    const cart = [];
    const cartBody = document.getElementById('svc-cart-body');
    const lineFields = document.getElementById('svc-line-fields');
    const searchInput = document.getElementById('svc-item-search');
    const categoryFilter = document.getElementById('svc-category-filter');
    const results = document.getElementById('svc-search-results');
    let lineIndex = 0;

    function money(n) { return '₹' + Number(n).toFixed(2); }
    function lineTotal(line) {
        const sub = line.qty * line.unit_price_ex_gst - (line.discount || 0);
        const tax = sub * (line.gst_rate / 100);
        return sub + tax;
    }
    function render() {
        cartBody.innerHTML = cart.map((line, i) => `
            <tr>
                <td>${line.description}</td>
                <td>${line.sac_code || '—'}</td>
                <td class="text-end">${line.qty}</td>
                <td class="text-end">${line.unit_price_ex_gst.toFixed(2)}</td>
                <td class="text-end">${line.gst_rate}%</td>
                <td class="text-end">${lineTotal(line).toFixed(2)}</td>
                <td><button type="button" class="btn btn-sm btn-link text-danger" data-remove="${i}">Remove</button></td>
            </tr>`).join('');
        lineFields.innerHTML = cart.map((line, i) => `
            <input type="hidden" name="lines[${i}][service_item_id]" value="${line.service_item_id || ''}">
            <input type="hidden" name="lines[${i}][description]" value="${line.description.replace(/"/g, '&quot;')}">
            <input type="hidden" name="lines[${i}][qty]" value="${line.qty}">
            <input type="hidden" name="lines[${i}][unit_price_ex_gst]" value="${line.unit_price_ex_gst}">
            <input type="hidden" name="lines[${i}][sac_code]" value="${line.sac_code || ''}">
            <input type="hidden" name="lines[${i}][gst_rate]" value="${line.gst_rate}">
        `).join('');
        let subtotal = 0, tax = 0;
        cart.forEach(line => {
            const taxable = line.qty * line.unit_price_ex_gst;
            subtotal += taxable;
            tax += taxable * (line.gst_rate / 100);
        });
        document.getElementById('svc-subtotal').textContent = money(subtotal);
        document.getElementById('svc-tax').textContent = money(tax);
        document.getElementById('svc-total').textContent = money(subtotal + tax);
        cartBody.querySelectorAll('[data-remove]').forEach(btn => btn.addEventListener('click', () => {
            cart.splice(Number(btn.dataset.remove), 1);
            render();
        }));
    }
    function addLine(line) { cart.push(line); render(); }
    async function search() {
        const q = searchInput.value.trim();
        if (q.length < 1) { results.innerHTML = ''; return; }
        const params = new URLSearchParams({ q, category_id: categoryFilter.value || '' });
        const res = await fetch(`${searchUrl}?${params}`);
        const data = await res.json();
        results.innerHTML = data.items.map(item => `
            <button type="button" class="list-group-item list-group-item-action" data-item='${JSON.stringify(item)}'>
                <div class="fw-semibold">${item.name}</div>
                <div class="small text-muted">${item.category || ''} · SAC ${item.sac_code || '—'} · ₹${item.price_ex_gst} ex-GST</div>
            </button>`).join('');
        results.querySelectorAll('[data-item]').forEach(btn => btn.addEventListener('click', () => {
            const item = JSON.parse(btn.dataset.item);
            addLine({ service_item_id: item.id, description: item.name, qty: 1, unit_price_ex_gst: item.price_ex_gst, sac_code: item.sac_code, gst_rate: item.gst_rate, discount: 0 });
            results.innerHTML = '';
            searchInput.value = '';
        }));
    }
    searchInput?.addEventListener('input', search);
    categoryFilter?.addEventListener('change', search);
    document.getElementById('svc-add-custom')?.addEventListener('click', () => {
        const desc = prompt('Custom service description');
        if (!desc) return;
        const price = Number(prompt('Price ex-GST', '0') || 0);
        const sac = prompt('SAC (6 digits)', '998313') || '';
        const gst = Number(prompt('GST %', '18') || 18);
        addLine({ service_item_id: null, description: desc, qty: 1, unit_price_ex_gst: price, sac_code: sac, gst_rate: gst, discount: 0 });
    });
})();
</script>
@endpush
