<div class="settings-center-card">
    <div class="settings-center-card__header">
        <div class="settings-center-card__heading">
            <h2 class="settings-center-card__title">Service Cases</h2>
            <p class="settings-center-card__description">Manage product types available for new service requests.</p>
        </div>
    </div>
    <div class="settings-center-card__body settings-center-card__body--flush">
        <div class="table-responsive">
            <table class="table settings-center-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Sort Order</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($products as $product)
                        <tr>
                            <td>
                                <span @class([
                                    'settings-center-status-pill settings-center-status-pill--sm',
                                    'settings-center-status-pill--success' => $product->is_enabled,
                                    'settings-center-status-pill--neutral' => ! $product->is_enabled,
                                ])>
                                    <span class="settings-center-status-pill__dot" aria-hidden="true"></span>
                                    {{ $product->is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </td>
                            <td>
                                <form method="POST" action="{{ route('settings.products.update', $product) }}" id="product-form-{{ $product->id }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="name" class="form-control form-control-sm settings-center-table-input" value="{{ $product->name }}" required form="product-form-{{ $product->id }}">
                            </td>
                            <td>
                                    <input type="number" name="sort_order" class="form-control form-control-sm settings-center-table-input settings-center-table-input--narrow" value="{{ $product->sort_order }}" min="0" required form="product-form-{{ $product->id }}">
                            </td>
                            <td class="text-end">
                                </form>
                                <x-settings-center.table-actions
                                    :save-form-id="'product-form-'.$product->id"
                                    :toggle-url="route('settings.products.toggle', $product)"
                                    :is-enabled="$product->is_enabled"
                                    entity-label="product"
                                />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="settings-center-card mt-3">
    <div class="settings-center-card__header">
        <h2 class="settings-center-card__title">Add Service Case Type</h2>
    </div>
    <div class="settings-center-card__body">
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
