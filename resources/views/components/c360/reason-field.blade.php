@props([
    'id',
    'name' => 'reason',
    'label',
    'value' => '',
    'required' => true,
    'rows' => 5,
    'compact' => false,
    'showCounter' => false,
    'maxlength' => 2000,
])

@php
    $resolvedRows = $compact ? 3 : $rows;
    $initialLength = mb_strlen((string) $value);
@endphp

<div {{ $attributes->merge(['class' => 'c360-dialog-field mb-0'.($compact ? ' c360-dialog-field--compact-reason' : '')]) }}>
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
              rows="{{ $resolvedRows }}"
              class="form-control c360-dialog-reason-textarea @error($name) is-invalid @enderror"
              maxlength="{{ $maxlength }}"
              placeholder="Customer shared a clear photo of the device label via WhatsApp.{{ PHP_EOL }}Verified against invoice before correction."
              aria-describedby="{{ $id }}-helper{{ $showCounter ? ' '.$id.'-counter' : '' }}"
              @if($showCounter) data-c360-reason-counter-input @endif
              @if($required) required @endif>{{ $value }}</textarea>
    @if($showCounter)
        <div class="c360-dialog-reason-footer">
            <span class="c360-dialog-reason-counter"
                  id="{{ $id }}-counter"
                  data-c360-reason-counter
                  aria-live="polite">
                <span data-c360-reason-counter-current>{{ $initialLength }}</span>/{{ $maxlength }}
            </span>
        </div>
    @endif
    @error($name)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>
