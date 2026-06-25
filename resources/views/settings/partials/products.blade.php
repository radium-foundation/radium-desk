<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Products</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Sort Order</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr>
                            <td>
                                <form method="POST" action="{{ route('settings.products.update', $product) }}" id="product-form-{{ $product->id }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="name" class="form-control form-control-sm" value="{{ $product->name }}" required form="product-form-{{ $product->id }}">
                            </td>
                            <td>
                                    <input type="number" name="sort_order" class="form-control form-control-sm" value="{{ $product->sort_order }}" min="0" required form="product-form-{{ $product->id }}">
                            </td>
                            <td>
                                @if($product->is_enabled)
                                    <span class="badge text-bg-success">Enabled</span>
                                @else
                                    <span class="badge text-bg-secondary">Disabled</span>
                                @endif
                            </td>
                            <td class="text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" form="product-form-{{ $product->id }}">Save</button>
                                </form>
                                <form method="POST" action="{{ route('settings.products.toggle', $product) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-{{ $product->is_enabled ? 'warning' : 'success' }}">
                                        {{ $product->is_enabled ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Add Product</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.products.store') }}" class="row g-3 align-items-end">
            @csrf
            <div class="col-md-6">
                <label for="product_name" class="form-label">Product Name</label>
                <input type="text" name="name" id="product_name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="product_sort_order" class="form-label">Sort Order</label>
                <input type="number" name="sort_order" id="product_sort_order" class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', ($products->max('sort_order') ?? 0) + 1) }}" min="0" required>
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Add Product</button>
            </div>
        </form>
        <p class="small text-muted mt-3 mb-0">Disabling a product hides it from new service requests. Existing records keep their product values.</p>
    </div>
</div>
