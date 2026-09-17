@csrf
<div class="row g-3">
    <div class="col-md-4">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select" required>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('category_id', $item->category_id ?? 0) === (int) $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Code / SKU</label>
        <input type="text" name="code" class="form-control" value="{{ old('code', $item->code ?? '') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label">Duration label</label>
        <input type="text" name="duration_label" class="form-control" value="{{ old('duration_label', $item->duration_label ?? '') }}">
    </div>
    <div class="col-12">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" value="{{ old('name', $item->name ?? '') }}" required>
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2">{{ old('description', $item->description ?? '') }}</textarea>
    </div>
    <div class="col-md-3">
        <label class="form-label">SAC (6 digits)</label>
        <input type="text" name="sac_code" class="form-control" value="{{ old('sac_code', $item->sac_code ?? '') }}" pattern="\d{6}">
    </div>
    <div class="col-md-3">
        <label class="form-label">GST %</label>
        <input type="number" step="0.01" name="gst_rate" class="form-control" value="{{ old('gst_rate', $item->gst_rate ?? 18) }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Price ex-GST</label>
        <input type="number" step="0.01" name="price_ex_gst" class="form-control" value="{{ old('price_ex_gst', $item->price_ex_gst ?? 0) }}" required>
    </div>
    <div class="col-md-3">
        <label class="form-label">Price incl-GST</label>
        <input type="number" step="0.01" name="price_incl_gst" class="form-control" value="{{ old('price_incl_gst', $item->price_incl_gst ?? '') }}">
    </div>
    <div class="col-12">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $item->is_active ?? true))>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>
