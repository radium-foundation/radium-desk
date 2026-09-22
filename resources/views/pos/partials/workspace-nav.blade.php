@props([
    'active' => 'counter',
])

@php
    $commerceActive = match ($active) {
        'counter' => 'sell_products',
        'sales' => 'product_sales',
        'upi' => 'upi',
        'upi-verify' => 'upi_verify',
        default => $active,
    };
@endphp

@include('commerce.partials.workspace-nav', ['active' => $commerceActive])
