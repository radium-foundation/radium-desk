@php
    $product = $product ?? null;
@endphp

<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">SKU</label>
        <input type="text" name="sku" class="form-control" required value="{{ old('sku', $product?->sku) }}">
        @error('sku')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-8">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required value="{{ old('name', $product?->name) }}">
        @error('name')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label class="form-label">HSN</label>
        <input type="text" name="hsn_code" class="form-control" value="{{ old('hsn_code', $product?->hsn_code) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">GST %</label>
        <input type="number" step="0.01" min="0" max="100" name="gst_percentage" class="form-control" required value="{{ old('gst_percentage', $product?->gst_percentage ?? 18) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">Unit price</label>
        <input type="number" step="0.01" min="0" name="unit_price" class="form-control" required value="{{ old('unit_price', $product?->unit_price ?? 0) }}">
    </div>
    <div class="col-md-3">
        <label class="form-label">Device model (optional)</label>
        <select name="device_model_id" class="form-select">
            <option value="">None</option>
            @foreach($deviceModels as $model)
                <option value="{{ $model->id }}" @selected(old('device_model_id', $product?->device_model_id) == $model->id)>{{ $model->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="is_serialized" value="1" id="is_serialized" @checked(old('is_serialized', $product?->is_serialized ?? true))>
            <label class="form-check-label" for="is_serialized">Serialised tracking</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="tracks_batch" value="1" id="tracks_batch" @checked(old('tracks_batch', $product?->tracks_batch ?? false))>
            <label class="form-check-label" for="tracks_batch">Require batch/lot on stock-in</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $product?->is_active ?? true))>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>

<h2 class="h5 mt-4">Variants</h2>
<p class="text-muted small">Optional child SKUs. Leave blank if the product has no variants.</p>
@php($variants = old('variants', $product?->variants?->map(fn ($v) => ['sku' => $v->sku, 'name' => $v->name, 'unit_price' => $v->unit_price, 'is_active' => $v->is_active])->all() ?? [[]]))
@foreach($variants as $i => $variant)
    <div class="row g-2 mb-2">
        <div class="col-md-3"><input class="form-control" name="variants[{{ $i }}][sku]" placeholder="SKU" value="{{ $variant['sku'] ?? '' }}"></div>
        <div class="col-md-4"><input class="form-control" name="variants[{{ $i }}][name]" placeholder="Name" value="{{ $variant['name'] ?? '' }}"></div>
        <div class="col-md-3"><input class="form-control" name="variants[{{ $i }}][unit_price]" placeholder="Price override" value="{{ $variant['unit_price'] ?? '' }}"></div>
        <div class="col-md-2">
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="variants[{{ $i }}][is_active]" value="1" @checked($variant['is_active'] ?? true)>
                <label class="form-check-label">Active</label>
            </div>
        </div>
    </div>
@endforeach
<div class="row g-2">
    <div class="col-md-3"><input class="form-control" name="variants[{{ count($variants) }}][sku]" placeholder="New SKU"></div>
    <div class="col-md-4"><input class="form-control" name="variants[{{ count($variants) }}][name]" placeholder="New name"></div>
    <div class="col-md-3"><input class="form-control" name="variants[{{ count($variants) }}][unit_price]" placeholder="Price override"></div>
    <div class="col-md-2">
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="variants[{{ count($variants) }}][is_active]" value="1" checked>
            <label class="form-check-label">Active</label>
        </div>
    </div>
</div>
