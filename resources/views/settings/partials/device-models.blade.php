@php
    $deviceModelHiddenFields = ['tab' => 'device-models'];
    if (request('search')) {
        $deviceModelHiddenFields['search'] = request('search');
    }
@endphp

<x-settings-center.card
    title="Models"
    description="Device models available for assignment and intake."
    icon="monitor"
    flush
    class="mb-3"
>
    <x-slot:headerActions>
        <x-settings-center.table-toolbar
            :action="route('settings.index')"
            :hidden-fields="$deviceModelHiddenFields"
            search-name="search"
            :search-value="request('search')"
            search-placeholder="Search name, code, brand…"
            :clear-url="request('search') ? route('settings.index', ['tab' => 'device-models']) : null"
        />
    </x-slot:headerActions>

    <div class="table-responsive settings-center-table-wrap">
        <table class="table settings-center-table align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Brand</th>
                    <th>Driver URL</th>
                    <th>Buy Device URL</th>
                    <th>Buy RD URL</th>
                    <th>Order</th>
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
                                <input type="text" name="name" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->name }}" required form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="text" name="code" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->code }}" form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="text" name="brand" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->brand }}" form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="url" name="driver_download_url" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->driver_download_url }}" placeholder="https://…" form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="url" name="buy_device_url" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->buy_device_url }}" placeholder="https://…" form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="url" name="buy_rd_service_url" class="form-control form-control-sm settings-center-table-input" value="{{ $deviceModel->buy_rd_service_url }}" placeholder="https://…" form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                                <input type="number" name="display_order" class="form-control form-control-sm settings-center-table-input settings-center-table-input--narrow" value="{{ $deviceModel->display_order }}" min="0" required form="device-model-form-{{ $deviceModel->id }}">
                        </td>
                        <td>
                            <x-settings-center.status-pill
                                :label="$deviceModel->is_active ? 'Enabled' : 'Disabled'"
                                :tone="$deviceModel->is_active ? 'success' : 'neutral'"
                                size="sm"
                            />
                        </td>
                        <td class="text-end">
                            </form>
                            <x-settings-center.table-actions
                                :save-form-id="'device-model-form-'.$deviceModel->id"
                                :toggle-url="route('settings.device-models.toggle', $deviceModel)"
                                :is-enabled="$deviceModel->is_active"
                                entity-label="model"
                            />
                        </td>
                    </tr>
                @empty
                    <x-settings-center.table-empty :colspan="9" message="No models found." />
                @endforelse
            </tbody>
        </table>
    </div>

    @if($deviceModels->hasPages())
        <div class="settings-center-card__footer">
            {{ $deviceModels->links() }}
        </div>
    @endif
</x-settings-center.card>

<x-settings-center.card title="Add Model">
    <form method="POST" action="{{ route('settings.device-models.store') }}" class="row g-3 align-items-end settings-center-form">
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
        <div class="col-md-3">
            <label for="device_model_brand" class="form-label">Brand</label>
            <input type="text" name="brand" id="device_model_brand" class="form-control @error('brand') is-invalid @enderror" value="{{ old('brand') }}">
            @error('brand')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label for="device_model_display_order" class="form-label">Display Order</label>
            <input type="number" name="display_order" id="device_model_display_order" class="form-control @error('display_order') is-invalid @enderror"
                   value="{{ old('display_order', ($deviceModels->max('display_order') ?? 0) + 1) }}" min="0" required>
            @error('display_order')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Add</button>
        </div>
    </form>
    <p class="settings-center-helper">Deactivating a model hides it from assignment. Existing order assignments are preserved.</p>
</x-settings-center.card>

@include('settings.partials.device-model-aliases')
