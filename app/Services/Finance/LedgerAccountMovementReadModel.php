<?php

namespace App\Services\Finance;

use App\Models\FinanceBankAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceJournalLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GL line read model for cash/bank ledger screens.
 */
class LedgerAccountMovementReadModel
{
    public function __construct(
        private readonly AccountBalanceReadModel $balances,
    ) {}

    /**
     * @param  list<int>  $accountIds
     * @return array{balance: float, lines: LengthAwarePaginator}
     */
    public function forAccounts(array $accountIds, int $perPage = 50): array
    {
        $accountIds = array_values(array_unique(array_filter($accountIds)));

        if ($accountIds === []) {
            return [
                'balance' => 0.0,
                'lines' => FinanceJournalLine::query()->whereRaw('1 = 0')->paginate($perPage),
            ];
        }

        $balance = 0.0;
        foreach ($accountIds as $accountId) {
            $balance += $this->balances->balance($accountId);
        }

        $lines = FinanceJournalLine::query()
            ->select('finance_journal_lines.*')
            ->join('finance_journals', 'finance_journals.id', '=', 'finance_journal_lines.journal_id')
            ->with(['journal.poster', 'account'])
            ->whereIn('finance_journal_lines.account_id', $accountIds)
            ->orderByDesc('finance_journals.entry_date')
            ->orderByDesc('finance_journal_lines.id')
            ->paginate($perPage);

        return [
            'balance' => round($balance, 2),
            'lines' => $lines,
        ];
    }

    /**
     * @return Collection<int, array{id: int, label: string, gl_account_id: int|null, balance: float}>
     */
    public function summarizeCashAccounts(): Collection
    {
        return FinanceCashAccount::query()
            ->with('glAccount')
            ->ordered()
            ->get()
            ->map(fn (FinanceCashAccount $account): array => [
                'id' => $account->id,
                'label' => $account->name,
                'gl_account_id' => $account->gl_account_id,
                'balance' => $account->gl_account_id
                    ? $this->balances->balance((int) $account->gl_account_id)
                    : 0.0,
            ]);
    }

    /**
     * @return Collection<int, array{id: int, label: string, gl_account_id: int|null, balance: float}>
     */
    public function summarizeBankAccounts(): Collection
    {
        return FinanceBankAccount::query()
            ->with('glAccount')
            ->ordered()
            ->get()
            ->map(fn (FinanceBankAccount $account): array => [
                'id' => $account->id,
                'label' => $account->bank_name.' · '.$account->account_name.' ('.$account->last_four.')',
                'gl_account_id' => $account->gl_account_id,
                'balance' => $account->gl_account_id
                    ? $this->balances->balance((int) $account->gl_account_id)
                    : 0.0,
            ]);
    }
}
