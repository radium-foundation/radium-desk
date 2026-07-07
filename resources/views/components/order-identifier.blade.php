@props([
    'order',
    'href' => null,
])

@php
    $isLegacyImported = $order->isLegacyImported();
    $tooltip = $isLegacyImported ? $order->legacyImportTooltipTitle() : null;
@endphp

@if($href)
    <a href="{{ $href }}"
       {{ $attributes->class([
           'text-decoration-none',
           'legacy-imported-order-id' => $isLegacyImported,
       ]) }}
       @if($isLegacyImported)
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           data-bs-title="{{ $tooltip }}"
           aria-label="{{ $tooltip }}"
       @endif>
        {{ $order->order_id }}
        @if($isLegacyImported)
            <span class="legacy-imported-order-indicator" aria-hidden="true">↓</span>
        @endif
    </a>
@else
    <span {{ $attributes->class([
        'legacy-imported-order-id' => $isLegacyImported,
    ]) }}
    @if($isLegacyImported)
        data-bs-toggle="tooltip"
        data-bs-placement="top"
        data-bs-title="{{ $tooltip }}"
        aria-label="{{ $tooltip }}"
    @endif>
        {{ $order->order_id }}
        @if($isLegacyImported)
            <span class="legacy-imported-order-indicator" aria-hidden="true">↓</span>
        @endif
    </span>
@endif
