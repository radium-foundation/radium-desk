@props([
    'order',
    'incident' => null,
    'href' => null,
])

@php
    $isInquiry = $order->isInquiryOrder();
    $caseReference = $incident?->display_reference ?: $order->inquiryCaseReference();
    $isLegacyImported = ! $isInquiry && $order->isLegacyImported();
    $tooltip = $isLegacyImported ? $order->legacyImportTooltipTitle() : null;
@endphp

@if($isInquiry)
    <span {{ $attributes->class(['order-identifier', 'order-identifier--enquiry', 'd-inline-flex', 'align-items-center', 'gap-1', 'flex-wrap']) }}>
        <span class="order-identifier__case">{{ $caseReference }}</span>
        <span class="badge rounded-pill text-bg-secondary order-identifier__type">Enquiry</span>
    </span>
@elseif($href)
    <a href="{{ $href }}"
       {{ $attributes->class([
           'text-decoration-none',
           'order-identifier',
           'order-identifier--order',
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
        'order-identifier',
        'order-identifier--order',
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
