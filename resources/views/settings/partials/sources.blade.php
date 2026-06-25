<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Sources</h2>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Key</th>
                        <th>Label</th>
                        <th>Icon</th>
                        <th>Sort</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sources as $source)
                        <tr>
                            <td><code>{{ $source->key }}</code></td>
                            <td>
                                <form method="POST" action="{{ route('settings.sources.update', $source) }}" id="source-form-{{ $source->id }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="label" class="form-control form-control-sm" value="{{ $source->label }}" required form="source-form-{{ $source->id }}">
                            </td>
                            <td>
                                    <input type="text" name="icon" class="form-control form-control-sm" value="{{ $source->icon }}" required form="source-form-{{ $source->id }}">
                            </td>
                            <td>
                                    <input type="number" name="sort_order" class="form-control form-control-sm" value="{{ $source->sort_order }}" min="0" required form="source-form-{{ $source->id }}">
                            </td>
                            <td>
                                @if($source->is_enabled)
                                    <span class="badge text-bg-success">On</span>
                                @else
                                    <span class="badge text-bg-secondary">Off</span>
                                @endif
                            </td>
                            <td class="text-end">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" form="source-form-{{ $source->id }}">Save</button>
                                </form>
                                <form method="POST" action="{{ route('settings.sources.toggle', $source) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-outline-{{ $source->is_enabled ? 'warning' : 'success' }}">
                                        {{ $source->is_enabled ? 'Disable' : 'Enable' }}
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
        <h2 class="h6 mb-0">Add Source</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.sources.store') }}" class="row g-3">
            @csrf
            <div class="col-md-3">
                <label for="source_key" class="form-label">Key</label>
                <input type="text" name="key" id="source_key" class="form-control @error('key') is-invalid @enderror"
                       value="{{ old('key') }}" placeholder="sms" required>
                @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="source_label" class="form-label">Label</label>
                <input type="text" name="label" id="source_label" class="form-control @error('label') is-invalid @enderror" value="{{ old('label') }}" required>
                @error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label for="source_icon" class="form-label">Icon</label>
                <input type="text" name="icon" id="source_icon" class="form-control @error('icon') is-invalid @enderror"
                       value="{{ old('icon', 'bi-chat-dots') }}" placeholder="bi-chat-dots" required>
                @error('icon')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <label for="source_sort_order" class="form-label">Sort Order</label>
                <input type="number" name="sort_order" id="source_sort_order" class="form-control @error('sort_order') is-invalid @enderror"
                       value="{{ old('sort_order', ($sources->max('sort_order') ?? 0) + 1) }}" min="0" required>
                @error('sort_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
    </div>
</div>
