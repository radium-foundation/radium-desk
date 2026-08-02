<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceExpenseCategory;
use App\Models\FinancePaymentMethod;

class FinanceMasterDataService
{
    public function __construct(
        private readonly FinanceSettingsService $settings,
    ) {}

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

    public function createExpenseCategory(string $name, ?int $defaultGlAccountId = null): FinanceExpenseCategory
    {
        return FinanceExpenseCategory::query()->create([
            'name' => $name,
            'default_gl_account_id' => $defaultGlAccountId
                ?? $this->settings->accountBySettingKey(FinanceSettingsService::KEY_DEFAULT_MISC_EXPENSE)?->id,
            'is_active' => true,
        ]);
    }

    public function updateExpenseCategory(
        FinanceExpenseCategory $category,
        string $name,
        ?int $defaultGlAccountId = null,
    ): FinanceExpenseCategory {
        $payload = ['name' => $name];
        if ($defaultGlAccountId !== null) {
            $payload['default_gl_account_id'] = $defaultGlAccountId;
        }
        $category->update($payload);

        return $category->fresh();
    }

    public function toggleExpenseCategory(FinanceExpenseCategory $category, bool $active): FinanceExpenseCategory
    {
        $category->update(['is_active' => $active]);

        return $category->fresh();
    }

    public function createCashAccount(string $name, ?int $glAccountId = null): FinanceCashAccount
    {
        return FinanceCashAccount::query()->create([
            'name' => $name,
            'gl_account_id' => $glAccountId ?? $this->settings->defaultCashAccount()?->id,
            'is_active' => true,
        ]);
    }

    public function updateCashAccount(FinanceCashAccount $account, string $name, ?int $glAccountId = null): FinanceCashAccount
    {
        $payload = ['name' => $name];
        if ($glAccountId !== null) {
            $payload['gl_account_id'] = $glAccountId;
        }
        $account->update($payload);

        return $account->fresh();
    }

    public function toggleCashAccount(FinanceCashAccount $account, bool $active): FinanceCashAccount
    {
        $account->update(['is_active' => $active]);

        return $account->fresh();
    }

    /**
     * @param  array{bank_name: string, account_name: string, last_four: string, gl_account_id?: int|null}  $attributes
     */
    public function createBankAccount(array $attributes): FinanceBankAccount
    {
        return FinanceBankAccount::query()->create([
            'bank_name' => $attributes['bank_name'],
            'account_name' => $attributes['account_name'],
            'last_four' => $attributes['last_four'],
            'gl_account_id' => $attributes['gl_account_id']
                ?? $this->settings->defaultBankClearingAccount()?->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{bank_name: string, account_name: string, last_four: string, gl_account_id?: int|null}  $attributes
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
