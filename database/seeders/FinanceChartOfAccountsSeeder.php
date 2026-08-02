<?php

namespace Database\Seeders;

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinanceSetting;
use Illuminate\Database\Seeder;

class FinanceChartOfAccountsSeeder extends Seeder
{
    public const CODE_CASH_ON_HAND = '1000';

    public const CODE_BANK_CLEARING = '1100';

    public const CODE_OPENING_EQUITY = '3000';

    public const CODE_SALES_INCOME = '4000';

    public const CODE_REFUND_EXPENSE = '5100';

    public const CODE_EXPENSE_COURIER = '6001';

    public const CODE_EXPENSE_OFFICE = '6002';

    public const CODE_EXPENSE_MARKETING = '6003';

    public const CODE_EXPENSE_SALARY = '6004';

    public const CODE_EXPENSE_TRAVEL = '6005';

    public const CODE_EXPENSE_MISC = '6099';

    public function run(): void
    {
        $accounts = [
            [self::CODE_CASH_ON_HAND, 'Cash on Hand', FinanceAccountType::Asset, 'Primary cash drawer GL'],
            [self::CODE_BANK_CLEARING, 'Bank / Payment Clearing', FinanceAccountType::Asset, 'Cashfree and bank receipts clearing'],
            [self::CODE_OPENING_EQUITY, 'Opening Balance Equity', FinanceAccountType::Equity, 'Opening balance offset'],
            [self::CODE_SALES_INCOME, 'Sales / RD Income', FinanceAccountType::Income, 'Customer order collections'],
            [self::CODE_REFUND_EXPENSE, 'Customer Refunds', FinanceAccountType::Expense, 'Completed customer refunds'],
            [self::CODE_EXPENSE_COURIER, 'Expense — Courier', FinanceAccountType::Expense, null],
            [self::CODE_EXPENSE_OFFICE, 'Expense — Office', FinanceAccountType::Expense, null],
            [self::CODE_EXPENSE_MARKETING, 'Expense — Marketing', FinanceAccountType::Expense, null],
            [self::CODE_EXPENSE_SALARY, 'Expense — Salary', FinanceAccountType::Expense, null],
            [self::CODE_EXPENSE_TRAVEL, 'Expense — Travel', FinanceAccountType::Expense, null],
            [self::CODE_EXPENSE_MISC, 'Expense — Miscellaneous', FinanceAccountType::Expense, null],
        ];

        foreach ($accounts as [$code, $name, $type, $description]) {
            FinanceAccount::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'is_system' => true,
                    'is_active' => true,
                    'description' => $description,
                ],
            );
        }

        $cashGl = FinanceAccount::query()->where('code', self::CODE_CASH_ON_HAND)->firstOrFail();
        $bankGl = FinanceAccount::query()->where('code', self::CODE_BANK_CLEARING)->firstOrFail();

        FinanceCashAccount::query()
            ->whereNull('gl_account_id')
            ->update(['gl_account_id' => $cashGl->id]);

        FinanceBankAccount::query()
            ->whereNull('gl_account_id')
            ->update(['gl_account_id' => $bankGl->id]);

        $categoryMap = [
            'Courier' => self::CODE_EXPENSE_COURIER,
            'Office' => self::CODE_EXPENSE_OFFICE,
            'Marketing' => self::CODE_EXPENSE_MARKETING,
            'Salary' => self::CODE_EXPENSE_SALARY,
            'Travel' => self::CODE_EXPENSE_TRAVEL,
            'Miscellaneous' => self::CODE_EXPENSE_MISC,
        ];

        foreach ($categoryMap as $name => $code) {
            $gl = FinanceAccount::query()->where('code', $code)->first();
            if ($gl === null) {
                continue;
            }

            FinanceExpenseCategory::query()
                ->where('name', $name)
                ->whereNull('default_gl_account_id')
                ->update(['default_gl_account_id' => $gl->id]);
        }

        FinanceSetting::putValue('default_revenue_account_code', self::CODE_SALES_INCOME);
        FinanceSetting::putValue('default_refund_account_code', self::CODE_REFUND_EXPENSE);
        FinanceSetting::putValue('default_bank_clearing_account_code', self::CODE_BANK_CLEARING);
        FinanceSetting::putValue('default_cash_account_code', self::CODE_CASH_ON_HAND);
        FinanceSetting::putValue('opening_equity_account_code', self::CODE_OPENING_EQUITY);
        FinanceSetting::putValue('default_misc_expense_account_code', self::CODE_EXPENSE_MISC);
        FinanceSetting::putValue('ledger_posting_enabled', '1');
    }
}
