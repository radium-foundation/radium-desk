@props([
    'active' => 'dashboard',
])

@php
    use App\Support\Finance\FinanceAccess;
    use Database\Seeders\RolePermissionSeeder;

    $user = auth()->user();

    $tabs = [
        'dashboard' => [
            'label' => 'Dashboard',
            'url' => route('finance.dashboard'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_DASHBOARD_VIEW),
        ],
        'payments' => [
            'label' => 'Customer Payments',
            'url' => route('finance.payments.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_PAYMENTS_VIEW),
        ],
        'expenses' => [
            'label' => 'Expenses',
            'url' => route('finance.expenses.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_EXPENSES_VIEW),
        ],
        'cash' => [
            'label' => 'Cash Ledger',
            'url' => route('finance.cash.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_CASH_VIEW),
        ],
        'closings' => [
            'label' => 'Daily Closing',
            'url' => route('finance.closings.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_CLOSINGS_VIEW),
        ],
        'bank' => [
            'label' => 'Bank Ledger',
            'url' => route('finance.bank.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_BANK_VIEW),
        ],
        'vendor_payments' => [
            'label' => 'Vendor Payments',
            'url' => route('finance.vendor-payments.index'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_VENDOR_PAYMENTS_VIEW),
        ],
        'settings' => [
            'label' => 'Settings',
            'url' => route('finance.settings.cash-accounts'),
            'visible' => FinanceAccess::allowsPermission($user, RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW),
        ],
        'invoices' => [
            'label' => 'Invoices',
            'url' => route('finance.invoices.index'),
            'visible' => FinanceAccess::allowsInvoices($user),
        ],
        'reports' => [
            'label' => 'Reports',
            'url' => route('finance.reports.index'),
            'visible' => FinanceAccess::allowsGstReports($user) || FinanceAccess::allowsSalesReports($user),
        ],
    ];
@endphp

<nav class="workspace-nav finance-workspace-nav mb-4" aria-label="Finance workspace">
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
