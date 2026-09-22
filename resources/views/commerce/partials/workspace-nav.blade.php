@props([
    'active' => 'sell_products',
])

@php
    use App\Models\Order;
    use App\Support\HardwareFulfilment\HardwareFulfilmentAccess;
    use App\Support\Inventory\PosAccess;
    use App\Support\Purchasing\PurchasingAccess;
    use App\Support\ServicePos\ServiceAccess;
    use Database\Seeders\RolePermissionSeeder;
    use Illuminate\Support\Facades\Gate;

    $user = auth()->user();

    $tabs = [];

    if (PosAccess::allows($user)) {
        $tabs['sell_products'] = [
            'label' => 'Sell Products',
            'url' => route('pos.counter.create'),
        ];
    }

    if (ServiceAccess::allowsSell($user)) {
        $tabs['sell_services'] = [
            'label' => 'Sell Services',
            'url' => route('service-pos.counter.create'),
        ];
        $tabs['service_sales'] = [
            'label' => 'Service Sales',
            'url' => route('service-pos.sales.index'),
        ];
    }

    if (PurchasingAccess::allows($user)) {
        $tabs['buy_products'] = [
            'label' => 'Buy Products',
            'url' => route('purchasing.purchase-orders.index'),
        ];
    }

    if (PosAccess::allows($user)) {
        $tabs['product_sales'] = [
            'label' => 'Product Sales',
            'url' => route('pos.sales.index'),
        ];
    }

    if (Gate::check('viewAny', Order::class)) {
        $tabs['orders'] = [
            'label' => 'Orders',
            'url' => route('orders.index'),
        ];
    }

    if (HardwareFulfilmentAccess::allows($user)) {
        $tabs['hardware'] = [
            'label' => 'Hardware',
            'url' => route('inventory.hardware-fulfilments.index'),
        ];
    }

    if (PurchasingAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_PURCHASE_VENDOR_MANAGE)) {
        $tabs['vendors'] = [
            'label' => 'Vendors',
            'url' => route('purchasing.vendors.index'),
        ];
    }

    if (PurchasingAccess::allows($user)) {
        $tabs['goods_receipts'] = [
            'label' => 'Goods Receipts',
            'url' => route('purchasing.goods-receipts.index'),
        ];
        $tabs['supplier_invoices'] = [
            'label' => 'Supplier Invoices',
            'url' => route('purchasing.supplier-invoices.index'),
        ];
        $tabs['purchase_payments'] = [
            'label' => 'Purchase Payments',
            'url' => route('purchasing.purchase-payments.index'),
        ];
    }

    $canSell = PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_SELL);
    $canVerify = PosAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY);

    if ($canSell || $canVerify) {
        $tabs['upi'] = [
            'label' => 'UPI pending',
            'url' => route('pos.upi.intents.index'),
        ];
    }

    if ($canVerify) {
        $tabs['upi_verify'] = [
            'label' => 'UPI verify',
            'url' => route('pos.upi.payments.index'),
        ];
    }
@endphp

@if(count($tabs) > 0)
    <nav class="workspace-nav commerce-workspace-nav mb-4" aria-label="Commerce workspace">
        <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
            @foreach($tabs as $key => $tab)
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
@endif
