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
       @if($tooltip) title="{{ $tooltip }}" @endif>
        {{ $order->order_id }}
    </a>
@else
    <span {{ $attributes->class([
        'legacy-imported-order-id' => $isLegacyImported,
    ]) }}
          @if($tooltip) title="{{ $tooltip }}" @endif>
        {{ $order->order_id }}
    </span>
@endif
