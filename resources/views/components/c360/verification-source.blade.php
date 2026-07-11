@props([
    'legend' => 'Verification source',
])

@php
    $options = [
        'Customer Call' => '📞 Customer Call',
        'WhatsApp' => '💬 WhatsApp',
        'Email' => '📧 Email',
        'Invoice' => '🧾 Invoice',
        'Technician Visit' => '👨‍🔧 Technician Visit',
        'Other' => '📄 Other',
    ];
@endphp

<fieldset {{ $attributes->merge(['class' => 'c360-dialog-verification-source c360-dialog-verification-source--select']) }}
          data-c360-verification-source>
    <legend class="visually-hidden">{{ $legend }}</legend>
    <select class="form-select c360-dialog-verification-source-select"
            name="c360_ui_verification_source"
            data-c360-verification-source-input
            aria-label="{{ $legend }} (optional)">
        <option value="">Select verification source…</option>
        @foreach($options as $value => $label)
            <option value="{{ $value }}">{{ $label }}</option>
        @endforeach
    </select>
</fieldset>
