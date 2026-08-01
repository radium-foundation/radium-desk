<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;
use App\Support\Finance\FinanceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(
                FinanceAccess::allowsPermission(
                    $request->user(),
                    RolePermissionSeeder::PERMISSION_FINANCE_SETTINGS_VIEW,
                ),
                403,
            );

            return $next($request);
        });
    }

    public function cashAccounts(): View
    {
        return view('finance.settings.cash-accounts', [
            'cashAccounts' => FinanceCashAccount::query()->ordered()->get(),
        ]);
    }

    public function bankAccounts(): View
    {
        return view('finance.settings.bank-accounts', [
            'bankAccounts' => FinanceBankAccount::query()->ordered()->get(),
        ]);
    }

    public function paymentMethods(): View
    {
        return view('finance.settings.payment-methods', [
            'paymentMethods' => FinancePaymentMethod::query()->ordered()->get(),
        ]);
    }

    public function expenseCategories(): View
    {
        return view('finance.settings.expense-categories', [
            'expenseCategories' => FinanceExpenseCategory::query()->ordered()->get(),
        ]);
    }

    public function vendorMaster(): View
    {
        return view('finance.settings.vendor-master');
    }

    public function financialPreferences(): View
    {
        return view('finance.settings.financial-preferences');
    }

    public function openingBalances(): View
    {
        return view('finance.settings.opening-balances');
    }
}
