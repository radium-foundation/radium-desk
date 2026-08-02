<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceSetting;
use Illuminate\Support\Carbon;

class FinanceSettingsService
{
    public const KEY_LEDGER_POSTING_ENABLED = 'ledger_posting_enabled';

    public const KEY_CUTOVER_DATE = 'ledger_cutover_date';

    public const KEY_DEFAULT_REVENUE = 'default_revenue_account_code';

    public const KEY_DEFAULT_REFUND = 'default_refund_account_code';

    public const KEY_DEFAULT_BANK_CLEARING = 'default_bank_clearing_account_code';

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
}
