@php
    $aliasHiddenFields = ['tab' => 'device-models'];
    if (request('search')) {
        $aliasHiddenFields['search'] = request('search');
    }
@endphp

<x-settings-center.card
    title="Aliases"
    description="Map legacy, vendor, and import labels to canonical device models."
    icon="sparkles"
    flush
    class="mb-3"
>
    <x-slot:headerActions>
        <x-settings-center.table-toolbar
            :action="route('settings.index')"
            :hidden-fields="$aliasHiddenFields"
            search-name="alias_search"
            :search-value="request('alias_search')"
            search-placeholder="Search alias or model…"
            :clear-url="request('alias_search') ? route('settings.index', array_filter(['tab' => 'device-models', 'search' => request('search')])) : null"
        />
    </x-slot:headerActions>

    <div class="table-responsive settings-center-table-wrap">
        <table class="table settings-center-table align-middle mb-0">
            <thead>
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
                                       class="form-control form-control-sm settings-center-table-input"
                                       value="{{ $deviceModelAlias->alias }}"
                                       required
                                       form="device-model-alias-form-{{ $deviceModelAlias->id }}">
                        </td>
                        <td><code>{{ $deviceModelAlias->normalized_alias }}</code></td>
                        <td>
                                <select name="device_model_id"
                                        class="form-select form-select-sm settings-center-table-input"
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
                            </form>
                            <x-settings-center.table-actions
                                :save-form-id="'device-model-alias-form-'.$deviceModelAlias->id"
                                :delete-url="route('settings.device-model-aliases.destroy', $deviceModelAlias)"
                                :delete-hidden-fields="array_filter([
                                    'search' => request('search'),
                                    'alias_search' => request('alias_search'),
                                ])"
                                delete-confirm="Delete this alias?"
                                entity-label="alias"
                            />
                        </td>
                    </tr>
                @empty
                    <x-settings-center.table-empty :colspan="4" message="No aliases found." />
                @endforelse
            </tbody>
        </table>
    </div>

    @if($deviceModelAliases->hasPages())
        <div class="settings-center-card__footer">
            {{ $deviceModelAliases->links() }}
        </div>
    @endif
</x-settings-center.card>

<x-settings-center.card title="Add Alias">
    <form method="POST" action="{{ route('settings.device-model-aliases.store') }}" class="row g-3 align-items-end settings-center-form">
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
    <p class="settings-center-helper">Aliases are normalized for identity lookup. Variants such as <code>MFS110</code>, <code>MFS 110</code>, and <code>MFS-110</code> resolve to the same normalized key.</p>
</x-settings-center.card>
