@php
    $selectId = $selectId ?? 'device_model_select';
    $fieldName = $fieldName ?? 'device_model_id';
    $hasError = $hasError ?? false;
    $showLabel = $showLabel ?? true;
    $selectedValue = $selected ?? old($fieldName);
@endphp

@if($showLabel)
    <label for="{{ $selectId }}" class="form-label small mb-1">Model</label>
@endif
<select id="{{ $selectId }}"
        name="{{ $fieldName }}"
        class="form-select{{ $selectId === 'device_model_select' ? ' form-select-sm' : '' }} @if($hasError || $errors->has($fieldName)) is-invalid @endif"
        @if($selectId === 'device_model_select') data-device-model-select @endif
        required>
    <option value="">Select Model</option>
    @foreach($deviceModels as $deviceModel)
        <option value="{{ $deviceModel['id'] ?? $deviceModel->id }}"
                @selected((string) $selectedValue === (string) ($deviceModel['id'] ?? $deviceModel->id))>
            {{ $deviceModel['name'] ?? $deviceModel->name }}
        </option>
    @endforeach
</select>
@error($fieldName)
    <div class="invalid-feedback d-block">{{ $message }}</div>
@enderror
