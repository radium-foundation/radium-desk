@props([
    'active' => 'purchase_orders',
])

@php
    $commerceActive = match ($active) {
        'purchase_orders' => 'buy_products',
        'vendors' => 'vendors',
        'goods_receipts' => 'goods_receipts',
        'supplier_invoices' => 'supplier_invoices',
        'purchase_payments' => 'purchase_payments',
        default => 'buy_products',
    };
@endphp

@include('commerce.partials.workspace-nav', ['active' => $commerceActive])
