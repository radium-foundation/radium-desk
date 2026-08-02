@props([
    'active' => 'cash_accounts',
])

@php
    $tabs = [
        'cash_accounts' => [
            'label' => 'Cash Accounts',
            'url' => route('finance.settings.cash-accounts'),
        ],
        'bank_accounts' => [
            'label' => 'Bank Accounts',
            'url' => route('finance.settings.bank-accounts'),
        ],
        'payment_methods' => [
            'label' => 'Payment Methods',
            'url' => route('finance.settings.payment-methods'),
        ],
        'expense_categories' => [
            'label' => 'Expense Categories',
            'url' => route('finance.settings.expense-categories'),
        ],
        'chart_of_accounts' => [
            'label' => 'Chart of Accounts',
            'url' => route('finance.settings.chart-of-accounts'),
        ],
        'journals' => [
            'label' => 'Journal Audit',
            'url' => route('finance.settings.journals'),
        ],
        'vendor_master' => [
            'label' => 'Vendor Master',
            'url' => route('finance.settings.vendor-master'),
        ],
        'financial_preferences' => [
            'label' => 'Financial Preferences',
            'url' => route('finance.settings.financial-preferences'),
        ],
        'opening_balances' => [
            'label' => 'Opening Balances',
            'url' => route('finance.settings.opening-balances'),
        ],
    ];
@endphp

<nav class="workspace-nav finance-settings-nav mb-4" aria-label="Finance settings">
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
