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
@endphp

@if($telUrl && ! $disabled)
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
