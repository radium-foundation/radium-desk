<?php

namespace App\Services\Finance;

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use App\Models\FinanceSetting;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinanceSettingsService
{
    public const KEY_LEDGER_POSTING_ENABLED = 'ledger_posting_enabled';

    public const KEY_CUTOVER_DATE = 'ledger_cutover_date';

    public const KEY_DEFAULT_REVENUE = 'default_revenue_account_code';

    public const KEY_DEFAULT_REFUND = 'default_refund_account_code';

    public const KEY_DEFAULT_BANK_CLEARING = 'default_bank_clearing_account_code';

    public const KEY_DEFAULT_WALLET_LIABILITY = 'default_wallet_liability_account_code';

    public const KEY_DEFAULT_CASH = 'default_cash_account_code';

    public const KEY_OPENING_EQUITY = 'opening_equity_account_code';

    public const KEY_DEFAULT_MISC_EXPENSE = 'default_misc_expense_account_code';

    public function isLedgerPostingEnabled(): bool
    {
        return FinanceSetting::getValue(self::KEY_LEDGER_POSTING_ENABLED, '1') === '1';
    }

    public function cutoverDate(): ?Carbon
    {
        $raw = FinanceSetting::getValue(self::KEY_CUTOVER_DATE);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function shouldPostForDate(\DateTimeInterface|string $date): bool
    {
        if (! $this->isLedgerPostingEnabled()) {
            return false;
        }

        $cutover = $this->cutoverDate();
        if ($cutover === null) {
            return true;
        }

        $entry = $date instanceof \DateTimeInterface
            ? Carbon::instance(\DateTimeImmutable::createFromInterface($date))->startOfDay()
            : Carbon::parse($date)->startOfDay();

        return $entry->greaterThanOrEqualTo($cutover);
    }

    public function accountBySettingKey(string $key): ?FinanceAccount
    {
        $code = FinanceSetting::getValue($key);
        if (! is_string($code) || $code === '') {
            return null;
        }

        return FinanceAccount::query()->where('code', $code)->where('is_active', true)->first();
    }

    public function defaultRevenueAccount(): ?FinanceAccount
    {
        return $this->accountBySettingKey(self::KEY_DEFAULT_REVENUE);
    }

    public function defaultRefundAccount(): ?FinanceAccount
    {
        return $this->accountBySettingKey(self::KEY_DEFAULT_REFUND);
    }

    public function defaultBankClearingAccount(): ?FinanceAccount
    {
        return $this->accountBySettingKey(self::KEY_DEFAULT_BANK_CLEARING);
    }

    public function defaultWalletLiabilityAccount(): ?FinanceAccount
    {
        return $this->walletLiabilityAccountOrNull();
    }

    /**
     * @throws ValidationException when wallet liability posting is required but misconfigured
     */
    public function requireWalletLiabilityAccountForRefund(): FinanceAccount
    {
        $account = $this->walletLiabilityAccountOrNull();
        if ($account === null) {
            throw ValidationException::withMessages([
                'wallet_liability' => 'Customer Wallet Liability account is not configured or is invalid for wallet refund posting.',
            ]);
        }

        return $account;
    }

    /**
     * @throws ValidationException when bank clearing posting is required but misconfigured
     */
    public function requireBankClearingAccountForRefund(): FinanceAccount
    {
        $account = $this->defaultBankClearingAccount();
        if ($account === null) {
            throw ValidationException::withMessages([
                'bank_clearing' => 'Bank / Payment Clearing account is not configured for external refund posting.',
            ]);
        }

        return $account;
    }

    public function defaultCashAccount(): ?FinanceAccount
    {
        return $this->accountBySettingKey(self::KEY_DEFAULT_CASH);
    }

    public function openingEquityAccount(): ?FinanceAccount
    {
        return $this->accountBySettingKey(self::KEY_OPENING_EQUITY);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            FinanceSetting::putValue($key, $value === null || $value === '' ? null : (string) $value);
        }
    }

    private function walletLiabilityAccountOrNull(): ?FinanceAccount
    {
        $account = $this->accountBySettingKey(self::KEY_DEFAULT_WALLET_LIABILITY);
        if ($account === null) {
            return null;
        }

        if ($account->type !== FinanceAccountType::Liability) {
            return null;
        }

        $bankClearingCode = FinanceSetting::getValue(self::KEY_DEFAULT_BANK_CLEARING);
        if (is_string($bankClearingCode) && $bankClearingCode !== '' && $account->code === $bankClearingCode) {
            return null;
        }

        return $account;
    }
}
