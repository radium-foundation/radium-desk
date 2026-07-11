@props([
    'legend' => 'Verification Source',
    'variant' => 'radio',
])

@php
    $selectOptions = [
        'Customer Call' => '📞 Customer Call',
        'WhatsApp' => '💬 WhatsApp',
        'Email' => '📧 Email',
        'Invoice' => '🧾 Invoice',
        'Technician Visit' => '👨‍🔧 Technician Visit',
        'Other' => '📄 Other',
    ];
    $radioOptions = array_keys($selectOptions);
@endphp

<fieldset {{ $attributes->merge(['class' => 'c360-dialog-verification-source'.($variant === 'select' ? ' c360-dialog-verification-source--select' : '')]) }}
          data-c360-verification-source>
    @if($variant !== 'select')
        <legend class="c360-dialog-verification-source-legend">
            {{ $legend }}
            <span class="c360-dialog-verification-source-optional">(optional)</span>
        </legend>
        <div class="c360-dialog-verification-source-options"
             role="radiogroup"
             aria-label="{{ $legend }}">
            @foreach($radioOptions as $option)
                <label class="c360-dialog-verification-source-option">
                    <input type="radio"
                           class="c360-dialog-verification-source-input"
                           name="c360_ui_verification_source"
                           value="{{ $option }}"
                           data-c360-verification-source-input>
                    <span class="c360-dialog-verification-source-label">{{ $option }}</span>
                </label>
            @endforeach
        </div>
    @else
        <legend class="visually-hidden">{{ $legend }}</legend>
        <select class="form-select c360-dialog-verification-source-select"
                name="c360_ui_verification_source"
                data-c360-verification-source-input
                aria-label="{{ $legend }} (optional)">
            <option value="">Select verification source…</option>
            @foreach($selectOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    @endif
</fieldset>
