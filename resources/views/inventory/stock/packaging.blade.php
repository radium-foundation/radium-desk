@extends('layouts.app')

@section('title', 'Record pack')

@section('content')
    <div class="mb-4">
        <p class="text-muted small text-uppercase fw-semibold mb-1">Inventory</p>
        <h1 class="h3 mb-1">Record packed shipment</h1>
        <p class="text-muted mb-0">Gross weight and outer carton size only. Device/net weight is not accepted. Units are kg and cm.</p>
    </div>

    @include('inventory.partials.workspace-nav', ['active' => 'stock'])

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-3">Product ID</dt>
                <dd class="col-sm-9">{{ $product->id }}</dd>
                <dt class="col-sm-3">SKU</dt>
                <dd class="col-sm-9">{{ $product->sku }}</dd>
                <dt class="col-sm-3">Product name</dt>
                <dd class="col-sm-9 mb-0">{{ $product->name }}</dd>
            </dl>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            @if($packaging)
                <p class="small text-muted">Last verified {{ $packaging->verified_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}@if($packaging->verifiedBy) by {{ $packaging->verifiedBy->name }}@endif. Saving again re-attests the pack.</p>
            @else
                <p class="small text-muted">Not verified. Enter measured packed values. Historical courier figures must not be pasted unless you measured this pack.</p>
            @endif

            <form method="POST" action="{{ route('inventory.stock.packaging.update', $product) }}">
                @csrf
                @method('PUT')
                @if(($filters['branch_id'] ?? '') !== '')
                    <input type="hidden" name="branch_id" value="{{ $filters['branch_id'] }}">
                @endif
                @if(($filters['product_id'] ?? '') !== '')
                    <input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">
                @endif

                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label" for="gross_weight">Gross weight</label>
                        <input type="number" step="0.001" min="0.001" name="gross_weight" id="gross_weight" class="form-control" required value="{{ old('gross_weight', $packaging?->gross_weight) }}">
                        @error('gross_weight')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="weight_unit">Weight unit</label>
                        <select name="weight_unit" id="weight_unit" class="form-select" required>
                            <option value="kg" @selected(old('weight_unit', $packaging?->weight_unit ?? 'kg') === 'kg')>kg</option>
                        </select>
                        @error('weight_unit')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="length">Length</label>
                        <input type="number" step="0.01" min="0.01" name="length" id="length" class="form-control" required value="{{ old('length', $packaging?->length) }}">
                        @error('length')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="breadth">Breadth</label>
                        <input type="number" step="0.01" min="0.01" name="breadth" id="breadth" class="form-control" required value="{{ old('breadth', $packaging?->breadth) }}">
                        @error('breadth')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="height">Height</label>
                        <input type="number" step="0.01" min="0.01" name="height" id="height" class="form-control" required value="{{ old('height', $packaging?->height) }}">
                        @error('height')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="dimension_unit">Dimension unit</label>
                        <select name="dimension_unit" id="dimension_unit" class="form-select" required>
                            <option value="cm" @selected(old('dimension_unit', $packaging?->dimension_unit ?? 'cm') === 'cm')>cm</option>
                        </select>
                        @error('dimension_unit')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="notes">Notes (optional)</label>
                        <input type="text" name="notes" id="notes" class="form-control" maxlength="255" value="{{ old('notes', $packaging?->notes) }}" placeholder="How this pack was measured">
                        @error('notes')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary">{{ $packaging ? 'Re-verify pack' : 'Verify pack' }}</button>
                    <a href="{{ route('inventory.stock.index', $filters) }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
