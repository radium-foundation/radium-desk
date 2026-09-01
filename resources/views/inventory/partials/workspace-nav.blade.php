@props([
    'active' => 'stock',
])

@php
    use App\Support\Inventory\InventoryAccess;
    use Database\Seeders\RolePermissionSeeder;

    $user = auth()->user();

    $tabs = [
        'stock' => [
            'label' => 'Stock',
            'url' => route('inventory.stock.index'),
            'visible' => true,
        ],
        'serials' => [
            'label' => 'Serials',
            'url' => route('inventory.serials.index'),
            'visible' => true,
        ],
        'transfers' => [
            'label' => 'Transfers',
            'url' => route('inventory.transfers.index'),
            'visible' => true,
        ],
        'reservations' => [
            'label' => 'Reservations',
            'url' => route('inventory.reservations.index'),
            'visible' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_RESERVE),
        ],
        'adjustments' => [
            'label' => 'Adjustments',
            'url' => route('inventory.adjustments.index'),
            'visible' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_ADJUST),
        ],
        'movements' => [
            'label' => 'Movements',
            'url' => route('inventory.movements.index'),
            'visible' => true,
        ],
        'products' => [
            'label' => 'Products',
            'url' => route('inventory.products.index'),
            'visible' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_PRODUCTS_MANAGE),
        ],
        'branches' => [
            'label' => 'Branches',
            'url' => route('inventory.branches.index'),
            'visible' => InventoryAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_INVENTORY_BRANCHES_MANAGE),
        ],
    ];
@endphp

<nav class="workspace-nav mb-4" aria-label="Inventory workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            @continue(! ($tab['visible'] ?? true))
            <li class="nav-item" role="presentation">
                <a @class(['nav-link', 'active' => $active === $key]) href="{{ $tab['url'] }}" @if($active === $key) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
