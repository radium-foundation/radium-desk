<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <div>
            <h2 class="h6 mb-0">Aliases</h2>
            <p class="small text-muted mb-0">Map legacy, vendor, and import labels to canonical device models.</p>
        </div>
        <form method="GET" action="{{ route('settings.index') }}" class="d-flex gap-2">
            <input type="hidden" name="tab" value="device-models">
            @if(request('search'))
                <input type="hidden" name="search" value="{{ request('search') }}">
            @endif
            <input type="search"
                   name="alias_search"
                   class="form-control form-control-sm"
                   placeholder="Search alias or model..."
                   value="{{ request('alias_search') }}"
                   aria-label="Search aliases">
            <button type="submit" class="btn btn-sm btn-outline-primary">Search</button>
            @if(request('alias_search'))
                <a href="{{ route('settings.index', ['tab' => 'device-models', 'search' => request('search')]) }}"
                   class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Alias</th>
                        <th>Normalized</th>
                        <th>Device Model</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($deviceModelAliases as $deviceModelAlias)
                        <tr>
                            <td>
                                <form method="POST"
                                      action="{{ route('settings.device-model-aliases.update', $deviceModelAlias) }}"
                                      id="device-model-alias-form-{{ $deviceModelAlias->id }}">
                                    @csrf
                                    @method('PUT')
                                    @if(request('search'))
                                        <input type="hidden" name="search" value="{{ request('search') }}">
                                    @endif
                                    @if(request('alias_search'))
                                        <input type="hidden" name="alias_search" value="{{ request('alias_search') }}">
                                    @endif
                                    <input type="text"
                                           name="alias"
                                           class="form-control form-control-sm"
                                           value="{{ $deviceModelAlias->alias }}"
                                           required
                                           form="device-model-alias-form-{{ $deviceModelAlias->id }}">
                            </td>
                            <td><code>{{ $deviceModelAlias->normalized_alias }}</code></td>
                            <td>
                                    <select name="device_model_id"
                                            class="form-select form-select-sm"
                                            required
                                            form="device-model-alias-form-{{ $deviceModelAlias->id }}">
                                        @foreach($deviceModelOptions as $deviceModelOption)
                                            <option value="{{ $deviceModelOption['id'] }}"
                                                @selected($deviceModelOption['id'] === $deviceModelAlias->device_model_id)>
                                                {{ $deviceModelOption['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                            </td>
                            <td class="text-end">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-primary"
                                            form="device-model-alias-form-{{ $deviceModelAlias->id }}">
                                        Save
                                    </button>
                                </form>
                                <form method="POST"
                                      action="{{ route('settings.device-model-aliases.destroy', $deviceModelAlias) }}"
                                      class="d-inline"
                                      onsubmit="return confirm('Delete this alias?');">
                                    @csrf
                                    @method('DELETE')
                                    @if(request('search'))
                                        <input type="hidden" name="search" value="{{ request('search') }}">
                                    @endif
                                    @if(request('alias_search'))
                                        <input type="hidden" name="alias_search" value="{{ request('alias_search') }}">
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No aliases found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($deviceModelAliases->hasPages())
        <div class="card-footer bg-white">
            {{ $deviceModelAliases->links() }}
        </div>
    @endif
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h2 class="h6 mb-0">Add Alias</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.device-model-aliases.store') }}" class="row g-3 align-items-end">
            @csrf
            @if(request('search'))
                <input type="hidden" name="search" value="{{ request('search') }}">
            @endif
            @if(request('alias_search'))
                <input type="hidden" name="alias_search" value="{{ request('alias_search') }}">
            @endif
            <div class="col-md-5">
                <label for="device_model_alias_text" class="form-label">Alias</label>
                <input type="text"
                       name="alias"
                       id="device_model_alias_text"
                       class="form-control @error('alias') is-invalid @enderror"
                       value="{{ old('alias') }}"
                       placeholder="Morpho MFS110"
                       required>
                @error('alias')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5">
                <label for="device_model_alias_model" class="form-label">Device Model</label>
                <select name="device_model_id"
                        id="device_model_alias_model"
                        class="form-select @error('device_model_id') is-invalid @enderror"
                        required>
                    <option value="" disabled @selected(old('device_model_id') === null)>Select model</option>
                    @foreach($deviceModelOptions as $deviceModelOption)
                        <option value="{{ $deviceModelOption['id'] }}" @selected((string) old('device_model_id') === (string) $deviceModelOption['id'])>
                            {{ $deviceModelOption['name'] }}
                        </option>
                    @endforeach
                </select>
                @error('device_model_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </form>
        <p class="small text-muted mt-3 mb-0">Aliases are normalized for identity lookup. Variants such as <code>MFS110</code>, <code>MFS 110</code>, and <code>MFS-110</code> resolve to the same normalized key.</p>
    </div>
</div>
