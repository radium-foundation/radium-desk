@extends('layouts.app')

@section('title', 'POS counter')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">POS</p>
        <h1 class="h3 mb-1">Retail counter</h1>
        <p class="text-muted mb-0">Completing a sale deducts stock and assigns serials from the same Desk inventory engine.</p>
    </div>

    @include('pos.partials.workspace-nav', ['active' => 'counter'])

    <form method="POST" action="{{ route('pos.counter.store') }}" id="pos-counter-form">
        @csrf
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>{{ $branch->code }} — {{ $branch->name }}</option>
                            @endforeach
                        </select>
                        @error('branch_id')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Customer name</label>
                        <input type="text" name="customer_name" class="form-control" required value="{{ old('customer_name') }}">
                        @error('customer_name')<div class="text-danger small">{{ $message }}</div>@enderror
                        @error('customer_phone')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="text" name="customer_phone" class="form-control" required value="{{ old('customer_phone') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="customer_email" class="form-control" value="{{ old('customer_email') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Payment method</label>
                        <select name="payment_method" class="form-select" required>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Payment reference</label>
                        <input type="text" name="payment_reference" class="form-control" value="{{ old('payment_reference') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Header discount</label>
                        <input type="number" step="0.01" min="0" name="discount" class="form-control" value="{{ old('discount', 0) }}">
                        @error('discount')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ old('notes') }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h5">Lines</h2>
                @error('lines')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                <div id="pos-lines">
                    @php($oldLines = old('lines', [['qty' => 1]]))
                    @foreach($oldLines as $i => $line)
                        <div class="border rounded p-3 mb-3 pos-line">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">Product</label>
                                    <select name="lines[{{ $i }}][product_id]" class="form-select pos-product" required>
                                        <option value="">Select</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}"
                                                    data-serialized="{{ $product->is_serialized ? '1' : '0' }}"
                                                    data-price="{{ $product->unit_price }}"
                                                    @selected(($line['product_id'] ?? '') == $product->id)>
                                                {{ $product->sku }} — {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error("lines.$i.product_id")<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Qty</label>
                                    <input type="number" min="1" name="lines[{{ $i }}][qty]" class="form-control" value="{{ $line['qty'] ?? 1 }}" required>
                                    @error("lines.$i.qty")<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Unit price</label>
                                    <input type="number" step="0.01" min="0" name="lines[{{ $i }}][unit_price]" class="form-control" value="{{ $line['unit_price'] ?? '' }}" placeholder="Default">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Line discount</label>
                                    <input type="number" step="0.01" min="0" name="lines[{{ $i }}][discount]" class="form-control" value="{{ $line['discount'] ?? 0 }}">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Serials (required when serialised)</label>
                                    <textarea name="lines[{{ $i }}][serials]" class="form-control" rows="2" placeholder="One per line">{{ $line['serials'] ?? '' }}</textarea>
                                    @error("lines.$i.serials")<div class="text-danger small">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="pos-add-line">Add line</button>
            </div>
        </div>

        <button class="btn btn-primary">Complete sale</button>
        <a href="{{ route('pos.sales.index') }}" class="btn btn-outline-secondary">Sale history</a>
    </form>
@endsection

@push('scripts')
    <script>
        (function () {
            const container = document.getElementById('pos-lines');
            const addButton = document.getElementById('pos-add-line');
            if (!container || !addButton) {
                return;
            }
            addButton.addEventListener('click', function () {
                const index = container.querySelectorAll('.pos-line').length;
                const first = container.querySelector('.pos-line');
                if (!first) {
                    return;
                }
                const clone = first.cloneNode(true);
                clone.querySelectorAll('select, input, textarea').forEach(function (field) {
                    const name = field.getAttribute('name');
                    if (name) {
                        field.setAttribute('name', name.replace(/lines\[\d+]/, 'lines[' + index + ']'));
                    }
                    if (field.tagName === 'TEXTAREA' || field.type === 'text' || field.type === 'number') {
                        field.value = field.name.includes('[qty]') ? '1' : (field.name.includes('[discount]') ? '0' : '');
                    }
                    if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                    }
                });
                container.appendChild(clone);
            });
        })();
    </script>
@endpush
