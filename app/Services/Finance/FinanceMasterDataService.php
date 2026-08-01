<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;

class FinanceMasterDataService
{
    public function createPaymentMethod(string $name): FinancePaymentMethod
    {
        return FinancePaymentMethod::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function updatePaymentMethod(FinancePaymentMethod $method, string $name): FinancePaymentMethod
    {
        $method->update(['name' => $name]);

        return $method->fresh();
    }

    public function togglePaymentMethod(FinancePaymentMethod $method, bool $active): FinancePaymentMethod
    {
        $method->update(['is_active' => $active]);

        return $method->fresh();
    }

    public function createExpenseCategory(string $name): FinanceExpenseCategory
    {
        return FinanceExpenseCategory::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function updateExpenseCategory(FinanceExpenseCategory $category, string $name): FinanceExpenseCategory
    {
        $category->update(['name' => $name]);

        return $category->fresh();
    }

    public function toggleExpenseCategory(FinanceExpenseCategory $category, bool $active): FinanceExpenseCategory
    {
        $category->update(['is_active' => $active]);

        return $category->fresh();
    }

    public function createCashAccount(string $name): FinanceCashAccount
    {
        return FinanceCashAccount::query()->create([
            'name' => $name,
            'is_active' => true,
        ]);
    }

    public function updateCashAccount(FinanceCashAccount $account, string $name): FinanceCashAccount
    {
        $account->update(['name' => $name]);

        return $account->fresh();
    }

    public function toggleCashAccount(FinanceCashAccount $account, bool $active): FinanceCashAccount
    {
        $account->update(['is_active' => $active]);

        return $account->fresh();
    }

    /**
     * @param  array{bank_name: string, account_name: string, last_four: string}  $attributes
     */
    public function createBankAccount(array $attributes): FinanceBankAccount
    {
        return FinanceBankAccount::query()->create([
            ...$attributes,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{bank_name: string, account_name: string, last_four: string}  $attributes
     */
    public function updateBankAccount(FinanceBankAccount $account, array $attributes): FinanceBankAccount
    {
        $account->update($attributes);

        return $account->fresh();
    }

    public function toggleBankAccount(FinanceBankAccount $account, bool $active): FinanceBankAccount
    {
        $account->update(['is_active' => $active]);

        return $account->fresh();
    }
}
