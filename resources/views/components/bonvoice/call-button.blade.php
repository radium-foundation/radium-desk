@props([
    'phone' => '',
    'orderId' => null,
    'incidentId' => null,
    'class' => '',
    'title' => 'Call customer',
    'ariaLabel' => 'Call customer',
    'shortcutAction' => null,
    'disabled' => false,
    'disabledTitle' => 'No phone number',
    'showLabel' => true,
    'iconClass' => 'bi bi-telephone',
])

@php
    $phone = trim((string) $phone);
    $telUrl = $phone !== '' ? 'tel:'.$phone : null;
    $apiEnabled = app(\App\Services\Bonvoice\BonvoiceClickToCallService::class)->isEnabled();
    $useApi = $apiEnabled && (filled($orderId) || filled($incidentId)) && $telUrl !== null;
    $callUrl = route('bonvoice.click-to-call');
@endphp

@if($telUrl && ! $disabled)
    @if($useApi)
        <button type="button"
                {{ $attributes->merge(['class' => $class]) }}
                data-bonvoice-click-to-call
                data-bonvoice-click-to-call-url="{{ $callUrl }}"
                @if(filled($orderId)) data-bonvoice-order-id="{{ $orderId }}" @endif
                @if(filled($incidentId)) data-bonvoice-incident-id="{{ $incidentId }}" @endif
                data-tel-fallback="{{ $telUrl }}"
                title="{{ $title }}"
                aria-label="{{ $ariaLabel }}"
                @if($shortcutAction) data-c360-shortcut-action="{{ $shortcutAction }}" @endif>
            <i class="{{ $iconClass }}" aria-hidden="true"></i>
            @if($showLabel)
                <span>Call</span>
            @endif
        </button>
    @else
        <a href="{{ $telUrl }}"
           {{ $attributes->merge(['class' => $class]) }}
           title="{{ $title }}"
           aria-label="{{ $ariaLabel }}"
           @if($shortcutAction) data-c360-shortcut-action="{{ $shortcutAction }}" @endif>
            <i class="{{ $iconClass }}" aria-hidden="true"></i>
            @if($showLabel)
                <span>Call</span>
            @endif
        </a>
    @endif
@else
    <button type="button"
            {{ $attributes->merge(['class' => $class]) }}
            disabled
            title="{{ $disabledTitle }}"
            aria-label="{{ $ariaLabel }} unavailable"
            @if($shortcutAction) data-c360-shortcut-action="{{ $shortcutAction }}" @endif>
        <i class="{{ $iconClass }}" aria-hidden="true"></i>
        @if($showLabel)
            <span>Call</span>
        @endif
    </button>
@endif
