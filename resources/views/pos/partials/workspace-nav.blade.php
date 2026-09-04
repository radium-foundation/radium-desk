@props([
    'active' => 'counter',
])

@php
    $tabs = [
        'counter' => ['label' => 'Counter', 'url' => route('pos.counter.create')],
        'sales' => ['label' => 'Sales', 'url' => route('pos.sales.index')],
    ];
    $user = auth()->user();
    $canSell = \App\Support\Inventory\PosAccess::allowsPermission($user, \Database\Seeders\RolePermissionSeeder::PERMISSION_POS_SELL);
    $canVerify = \App\Support\Inventory\PosAccess::allowsPermission($user, \Database\Seeders\RolePermissionSeeder::PERMISSION_POS_PAYMENTS_VERIFY);
    if ($canSell || $canVerify) {
        $tabs = array_slice($tabs, 0, 1, true)
            + ['upi' => ['label' => 'UPI pending', 'url' => route('pos.upi.intents.index')]]
            + array_slice($tabs, 1, null, true);
    }
    if ($canVerify) {
        $tabs['upi-verify'] = ['label' => 'UPI verify', 'url' => route('pos.upi.payments.index')];
    }
@endphp

<nav class="workspace-nav mb-4" aria-label="POS workspace">
    <ul class="nav nav-tabs workspace-nav-tabs flex-nowrap overflow-auto" role="tablist">
        @foreach($tabs as $key => $tab)
            <li class="nav-item" role="presentation">
                <a @class(['nav-link', 'active' => $active === $key]) href="{{ $tab['url'] }}" @if($active === $key) aria-current="page" @endif>
                    {{ $tab['label'] }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
