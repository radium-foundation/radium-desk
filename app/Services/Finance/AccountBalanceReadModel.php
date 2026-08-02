<?php

namespace App\Services\Finance;

use App\Enums\FinanceAccountType;
use App\Models\FinanceAccount;
use App\Models\FinanceJournalLine;
use Illuminate\Support\Facades\Cache;

/**
 * Computed balances from journal lines — never independently mutable.
 */
class AccountBalanceReadModel
{
    public const CACHE_PREFIX = 'finance:account-balance:';

    public const CACHE_TTL_SECONDS = 120;

    public function balance(int $accountId): float
    {
        return (float) Cache::remember(
            self::CACHE_PREFIX.$accountId,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): float => $this->compute($accountId),
        );
    }

    public function compute(int $accountId): float
    {
        $account = FinanceAccount::query()->find($accountId);
        if ($account === null) {
            return 0.0;
        }

        $totals = FinanceJournalLine::query()
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);

        /** @var FinanceAccountType $type */
        $type = $account->type;

        return $type->debitIncreases()
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }

    /**
     * @param  list<int>  $accountIds
     */
    public function invalidateMany(array $accountIds): void
    {
        foreach ($accountIds as $accountId) {
            Cache::forget(self::CACHE_PREFIX.$accountId);
        }
    }
}
