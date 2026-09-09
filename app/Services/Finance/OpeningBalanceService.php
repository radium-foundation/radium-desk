<?php

namespace App\Services\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceJournal;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use App\Support\Finance\LegacyCashContract;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly FinanceSettingsService $settings,
    ) {}

    public function postCashOpening(FinanceCashAccount $cashAccount, float $amount, User $actor, \DateTimeInterface|string $entryDate): FinanceJournal
    {
        if ($cashAccount->gl_account_id === null) {
            throw ValidationException::withMessages([
                'gl_account_id' => 'Cash account must be linked to a GL account before posting an opening balance.',
            ]);
        }

        $equity = $this->settings->openingEquityAccount();
        if ($equity === null) {
            throw ValidationException::withMessages([
                'opening_equity' => 'Opening equity account is not configured.',
            ]);
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Opening balance must be greater than zero.',
            ]);
        }

        return $this->journals->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: $cashAccount->id,
            idempotencyKey: 'opening:cash:'.$cashAccount->id,
            memo: 'Opening balance — '.$cashAccount->name,
            entryDate: $entryDate,
            lines: [
                JournalLineDraft::debit((int) $cashAccount->gl_account_id, $amount, 'Opening cash'),
                JournalLineDraft::credit($equity->id, $amount, 'Opening equity'),
            ],
            actor: $actor,
        );
    }

    public function postAccountOpening(FinanceAccount $account, float $amount, User $actor, \DateTimeInterface|string $entryDate): FinanceJournal
    {
        $equity = $this->settings->openingEquityAccount();
        if ($equity === null) {
            throw ValidationException::withMessages([
                'opening_equity' => 'Opening equity account is not configured.',
            ]);
        }

        $amount = round($amount, 2);
        if ($amount == 0.0) {
            throw ValidationException::withMessages([
                'amount' => 'Opening balance cannot be zero.',
            ]);
        }

        $lines = $amount > 0
            ? [
                JournalLineDraft::debit($account->id, $amount, 'Opening balance'),
                JournalLineDraft::credit($equity->id, $amount, 'Opening equity'),
            ]
            : [
                JournalLineDraft::debit($equity->id, abs($amount), 'Opening equity'),
                JournalLineDraft::credit($account->id, abs($amount), 'Opening balance'),
            ];

        return $this->journals->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: $account->id,
            idempotencyKey: 'opening:account:'.$account->id,
            memo: 'Opening balance — '.$account->code.' '.$account->name,
            entryDate: $entryDate,
            lines: $lines,
            actor: $actor,
        );
    }

    /**
     * Single operational opening for the approved RadiumBox Admin cash cutover.
     *
     * Does not post the 1,765 Legacy Cash rows. Uses a dedicated idempotency key
     * so the generic cash-account opening slot remains unused.
     */
    public function postLegacyAdminCashOpening(FinanceCashAccount $cashAccount, User $actor): FinanceJournal
    {
        if ($cashAccount->gl_account_id === null) {
            throw ValidationException::withMessages([
                'gl_account_id' => 'Cash account must be linked to a GL account before posting the legacy cash opening balance.',
            ]);
        }

        $equity = $this->settings->openingEquityAccount();
        if ($equity === null) {
            throw ValidationException::withMessages([
                'opening_equity' => 'Opening equity account is not configured.',
            ]);
        }

        $amount = (float) LegacyCashContract::OPENING_AMOUNT;
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Legacy cash opening balance must be greater than zero.',
            ]);
        }

        return $this->journals->post(
            sourceType: FinanceJournalSourceType::OpeningBalance,
            sourceId: $cashAccount->id,
            idempotencyKey: LegacyCashContract::OPENING_IDEMPOTENCY_KEY,
            memo: LegacyCashContract::OPENING_MEMO,
            entryDate: LegacyCashContract::OPENING_DATE,
            lines: [
                JournalLineDraft::debit(
                    (int) $cashAccount->gl_account_id,
                    $amount,
                    LegacyCashContract::OPENING_LINE_DESCRIPTION,
                ),
                JournalLineDraft::credit($equity->id, $amount, 'Opening equity — RadiumBox Admin legacy cash'),
            ],
            actor: $actor,
        );
    }
}
