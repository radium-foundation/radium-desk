<x-settings-center.card title="Sources" description="Incident source labels and icons for intake workflows." icon="flag" flush class="mb-3">
    <div class="table-responsive settings-center-table-wrap">
        <table class="table settings-center-table align-middle mb-0">
            <thead>
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
                                <input type="text" name="label" class="form-control form-control-sm settings-center-table-input" value="{{ $source->label }}" required form="source-form-{{ $source->id }}">
                        </td>
                        <td>
                                <input type="text" name="icon" class="form-control form-control-sm settings-center-table-input" value="{{ $source->icon }}" required form="source-form-{{ $source->id }}">
                        </td>
                        <td>
                                <input type="number" name="sort_order" class="form-control form-control-sm settings-center-table-input settings-center-table-input--narrow" value="{{ $source->sort_order }}" min="0" required form="source-form-{{ $source->id }}">
                        </td>
                        <td>
                            <x-settings-center.status-pill
                                :label="$source->is_enabled ? 'Enabled' : 'Disabled'"
                                :tone="$source->is_enabled ? 'success' : 'neutral'"
                                size="sm"
                            />
                        </td>
                        <td class="text-end">
                            </form>
                            <x-settings-center.table-actions
                                :save-form-id="'source-form-'.$source->id"
                                :toggle-url="route('settings.sources.toggle', $source)"
                                :is-enabled="$source->is_enabled"
                                entity-label="source"
                            />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-settings-center.card>

<x-settings-center.card title="Add Source">
    <form method="POST" action="{{ route('settings.sources.store') }}" class="row g-3 settings-center-form">
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
</x-settings-center.card>
