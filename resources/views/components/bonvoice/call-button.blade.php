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
    $clickToCallService = app(\App\Services\Bonvoice\BonvoiceClickToCallService::class);
    $apiEnabled = $clickToCallService->isEnabled();
    $agentClickToCallReady = auth()->user() !== null
        && $clickToCallService->normalizeDialablePhone(auth()->user()->bonvoice_extension) !== null;
    $useApi = $apiEnabled && (filled($orderId) || filled($incidentId)) && $telUrl !== null;
    $agentMobileMissing = $useApi && ! $agentClickToCallReady;
    $callUrl = route('bonvoice.click-to-call');
    $agentMobileTooltip = 'Configure your Click-to-Call Mobile in your profile.';
@endphp

@if($telUrl && ! $disabled && $useApi && ! $agentMobileMissing)
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
            <span data-bonvoice-call-status-label>Call</span>
        @endif
    </button>
@elseif($telUrl && ! $disabled && $useApi && $agentMobileMissing)
    <button type="button"
            {{ $attributes->merge(['class' => $class]) }}
            disabled
            title="{{ $agentMobileTooltip }}"
            aria-label="{{ $ariaLabel }} unavailable"
            @if($shortcutAction) data-c360-shortcut-action="{{ $shortcutAction }}" @endif>
        <i class="{{ $iconClass }}" aria-hidden="true"></i>
        @if($showLabel)
            <span>Call</span>
        @endif
    </button>
@elseif($telUrl && ! $disabled)
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
