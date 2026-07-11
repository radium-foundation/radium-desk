@props([
    'id',
    'name' => 'reason',
    'label',
    'value' => '',
    'required' => true,
    'rows' => 5,
])

<div {{ $attributes->merge(['class' => 'c360-dialog-field mb-0']) }}>
    <label for="{{ $id }}" class="form-label">
        {{ $label }}
        @if($required)
            <span class="text-danger" aria-hidden="true">*</span>
        @endif
    </label>
    <p class="c360-dialog-reason-helper" id="{{ $id }}-helper">
        Describe how the correct value was verified, the source, and why the previous value was incorrect.
    </p>
    <textarea id="{{ $id }}"
              name="{{ $name }}"
              rows="{{ $rows }}"
              class="form-control c360-dialog-reason-textarea @error($name) is-invalid @enderror"
              maxlength="2000"
              placeholder="Customer shared a clear photo of the device label via WhatsApp.{{ PHP_EOL }}Verified against invoice before correction."
              aria-describedby="{{ $id }}-helper"
              @if($required) required @endif>{{ $value }}</textarea>
    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
