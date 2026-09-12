@props([
    'active' => 'purchase_orders',
])

@php
    use App\Support\Purchasing\PurchasingAccess;
    use Database\Seeders\RolePermissionSeeder;

    $user = auth()->user();

    $tabs = [
        'purchase_orders' => [
            'label' => 'Purchase Orders',
            'url' => route('purchasing.purchase-orders.index'),
            'visible' => PurchasingAccess::allows($user),
        ],
        'vendors' => [
            'label' => 'Vendors',
            'url' => route('purchasing.vendors.index'),
            'visible' => PurchasingAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE),
        ],
        'goods_receipts' => [
            'label' => 'Goods Receipts',
            'url' => route('purchasing.goods-receipts.index'),
            'visible' => PurchasingAccess::allows($user),
        ],
        'supplier_invoices' => [
            'label' => 'Supplier Invoices',
            'url' => route('purchasing.supplier-invoices.index'),
            'visible' => PurchasingAccess::allows($user),
        ],
        'purchase_payments' => [
            'label' => 'Purchase Payments',
            'url' => route('purchasing.purchase-payments.index'),
            'visible' => PurchasingAccess::allows($user),
        ],
    ];
@endphp

<nav class="workspace-nav purchasing-workspace-nav mb-4" aria-label="Purchasing workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            @continue(! ($tab['visible'] ?? true))
            <li class="nav-item" role="presentation">
                <a
                    @class(['nav-link', 'active' => $active === $key])
                    href="{{ $tab['url'] }}"
                    @if($active === $key) aria-current="page" @endif
                >
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
