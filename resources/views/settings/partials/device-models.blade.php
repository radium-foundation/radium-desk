<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <h2 class="h6 mb-0">Device Models</h2>
        <form method="GET" action="{{ route('settings.index') }}" class="d-flex gap-2">
            <input type="hidden" name="tab" value="device-models">
            <input type="search"
                   name="search"
                   class="form-control form-control-sm"
                   placeholder="Search name, code, brand..."
                   value="{{ request('search') }}"
                   aria-label="Search device models">
            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            @if(request('search'))
                <a href="{{ route('settings.index', ['tab' => 'device-models']) }}"
                   class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Brand</th>
                        <th>Display Order</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deviceModels as $deviceModel)
                        <tr>
                            <td>
                                <form method="POST" action="{{ route('settings.device-models.update', $deviceModel) }}" id="device-model-form-{{ $deviceModel->id }}">
                                    @csrf
                                    @method('PUT')
                                    @if(request('search'))
                                        <input type="hidden" name="search" value="{{ request('search') }}">
                                    @endif
                                    <input type="text" name="name" class="form-control form-control-sm" value="{{ $deviceModel->name }}" required form="device-model-form-{{ $deviceModel->id }}">
                            </td>
                            <td>
                                    <input type="text" name="code" class="form-control form-control-sm" value="{{ $deviceModel->code }}" form="device-model-form-{{ $deviceModel->id }}">
                            </td>
                            <td>
                                    <input type="text" name="brand" class="form-control form-control-sm" value="{{ $deviceModel->brand }}" form="device-model-form-{{ $deviceModel->id }}">
                            </td>
                            <td>
                                    <input type="number" name="display_order" class="form-control form-control-sm" value="{{ $deviceModel->display_order }}" min="0" required form="device-model-form-{{ $deviceModel->id }}">
                            </td>
                            <td>
                                @if($deviceModel->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" form="device-model-form-{{ $deviceModel->id }}">Save</button>
                                </form>
                                <form method="POST" action="{{ route('settings.device-models.toggle', $deviceModel) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-{{ $deviceModel->is_active ? 'warning' : 'success' }}">
                                        {{ $deviceModel->is_active ? 'Deactivate' : 'Activate' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No device models found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($deviceModels->hasPages())
        <div class="card-footer bg-white">
            {{ $deviceModels->links() }}
        </div>
    @endif
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Add Device Model</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.device-models.store') }}" class="row g-3 align-items-end">
            @csrf
            <div class="col-md-4">
                <label for="device_model_name" class="form-label">Name</label>
                <input type="text" name="name" id="device_model_name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="device_model_code" class="form-label">Code</label>
                <input type="text" name="code" id="device_model_code" class="form-control @error('code') is-invalid @enderror" value="{{ old('code') }}">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="device_model_brand" class="form-label">Brand</label>
                <input type="text" name="brand" id="device_model_brand" class="form-control @error('brand') is-invalid @enderror" value="{{ old('brand') }}">
                @error('brand')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="device_model_display_order" class="form-label">Display Order</label>
                <input type="number" name="display_order" id="device_model_display_order" class="form-control @error('display_order') is-invalid @enderror"
                       value="{{ old('display_order', ($deviceModels->max('display_order') ?? 0) + 1) }}" min="0" required>
                @error('display_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
        <p class="small text-muted mt-3 mb-0">Deactivating a device model hides it from assignment. Existing order assignments are preserved.</p>
    </div>
</div>
