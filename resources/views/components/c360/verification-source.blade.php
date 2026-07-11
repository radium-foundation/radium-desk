@props([
    'legend' => 'Verification Source',
])

<fieldset {{ $attributes->merge(['class' => 'c360-dialog-verification-source']) }}
          data-c360-verification-source>
    <legend class="c360-dialog-verification-source-legend">
        {{ $legend }}
        <span class="c360-dialog-verification-source-optional">(optional)</span>
    </legend>
    <div class="c360-dialog-verification-source-options"
         role="radiogroup"
         aria-label="{{ $legend }}">
        @foreach(['Customer Call', 'WhatsApp', 'Invoice', 'Email', 'Technician Visit', 'Other'] as $option)
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
</fieldset>
