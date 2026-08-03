<?php

namespace App\Services\CashBook;

use App\Enums\CashBookExpenseCategory;
use App\Enums\FinanceJournalSourceType;
use App\Models\CashBookEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use App\Services\Finance\FinanceSettingsService;
use App\Services\Finance\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashBookEntryService
{
    public function __construct(
        private readonly CashBookReferenceService $references,
        private readonly JournalPostingService $journals,
        private readonly FinanceSettingsService $settings,
    ) {}

    /**
     * @param  array{
     *     type: string,
     *     amount: numeric,
     *     category: string,
     *     person?: string|null,
     *     remark: string,
     *     entry_date: string
     * }  $data
     */
    public function create(User $actor, array $data): CashBookEntry
    {
        return DB::transaction(function () use ($actor, $data): CashBookEntry {
            $entry = CashBookEntry::query()->create([
                'entry_no' => $this->references->generate(),
                'type' => $data['type'],
                'amount' => round((float) $data['amount'], 2),
                'category' => $data['category'],
                'person' => $this->nullablePerson($data['person'] ?? null),
                'remark' => $data['remark'],
                'entry_date' => $data['entry_date'],
                'created_by' => $actor->id,
            ]);

            $journal = $this->postJournal($entry, $actor, 'cashbook:'.$entry->id);
            if ($journal !== null) {
                $entry->update(['journal_id' => $journal->id]);
            }

            return $entry->fresh(['creator', 'journal']);
        });
    }

    /**
     * @param  array{
     *     type: string,
     *     amount: numeric,
     *     category: string,
     *     person?: string|null,
     *     remark: string,
     *     entry_date: string
     * }  $data
     */
    public function update(CashBookEntry $entry, User $actor, array $data): CashBookEntry
    {
        return DB::transaction(function () use ($entry, $actor, $data): CashBookEntry {
            $locked = CashBookEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reverseCurrentJournal($locked, $actor);

            $locked->update([
                'type' => $data['type'],
                'amount' => round((float) $data['amount'], 2),
                'category' => $data['category'],
                'person' => $this->nullablePerson($data['person'] ?? null),
                'remark' => $data['remark'],
                'entry_date' => $data['entry_date'],
                'updated_by' => $actor->id,
                'journal_id' => null,
            ]);

            $locked->refresh();

            $journal = $this->postJournal(
                $locked,
                $actor,
                'cashbook:'.$locked->id.':v'.now()->format('YmdHis'),
            );

            if ($journal !== null) {
                $locked->update(['journal_id' => $journal->id]);
            }

            return $locked->fresh(['creator', 'updater', 'journal']);
        });
    }

    public function delete(CashBookEntry $entry, User $actor): void
    {
        DB::transaction(function () use ($entry, $actor): void {
            $locked = CashBookEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->reverseCurrentJournal($locked, $actor);

            $locked->update([
                'deleted_by' => $actor->id,
                'journal_id' => null,
            ]);
            $locked->delete();
        });
    }

    private function reverseCurrentJournal(CashBookEntry $entry, User $actor): void
    {
        if ($entry->journal_id === null) {
            return;
        }

        $journal = FinanceJournal::query()
            ->with('lines')
            ->find($entry->journal_id);

        if ($journal === null || $journal->lines->isEmpty()) {
            return;
        }

        $lines = [];
        foreach ($journal->lines as $line) {
            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);

            if ($debit > 0) {
                $lines[] = JournalLineDraft::credit((int) $line->account_id, $debit, 'Cash book reversal');
            } elseif ($credit > 0) {
                $lines[] = JournalLineDraft::debit((int) $line->account_id, $credit, 'Cash book reversal');
            }
        }

        if ($lines === []) {
            return;
        }

        $this->journals->post(
            sourceType: FinanceJournalSourceType::CashBook,
            sourceId: $entry->id,
            idempotencyKey: 'cashbook:reverse:'.$entry->id.':'.$journal->id,
            memo: 'Reversal of '.$entry->entry_no,
            entryDate: $entry->entry_date,
            lines: $lines,
            actor: $actor,
        );
    }

    private function postJournal(CashBookEntry $entry, User $actor, string $idempotencyKey): ?FinanceJournal
    {
        if (! $this->settings->shouldPostForDate($entry->entry_date)) {
            return null;
        }

        $cash = $this->settings->defaultCashAccount();
        if ($cash === null) {
            throw ValidationException::withMessages([
                'amount' => 'Default cash GL account is not configured. Seed Finance chart of accounts first.',
            ]);
        }

        $amount = round((float) $entry->amount, 2);
        $description = $entry->remark;

        if ($entry->isIncome()) {
            $revenue = $this->settings->defaultRevenueAccount();
            if ($revenue === null) {
                throw ValidationException::withMessages([
                    'amount' => 'Default revenue GL account is not configured.',
                ]);
            }

            $lines = [
                JournalLineDraft::debit($cash->id, $amount, $description),
                JournalLineDraft::credit($revenue->id, $amount, $description),
            ];
        } else {
            $expenseGl = $this->resolveExpenseAccount($entry->category);
            $lines = [
                JournalLineDraft::debit($expenseGl->id, $amount, $description),
                JournalLineDraft::credit($cash->id, $amount, $description),
            ];
        }

        return $this->journals->post(
            sourceType: FinanceJournalSourceType::CashBook,
            sourceId: $entry->id,
            idempotencyKey: $idempotencyKey,
            memo: 'Cash book '.$entry->entry_no,
            entryDate: $entry->entry_date,
            lines: $lines,
            actor: $actor,
        );
    }

    private function resolveExpenseAccount(string $categoryValue): FinanceAccount
    {
        $category = CashBookExpenseCategory::tryFrom($categoryValue);
        $code = $category?->glAccountCode() ?? '6099';

        $account = FinanceAccount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'category' => "Expense GL account {$code} is not configured.",
            ]);
        }

        return $account;
    }

    private function nullablePerson(mixed $person): ?string
    {
        if (! is_string($person)) {
            return null;
        }

        $trimmed = trim($person);

        return $trimmed === '' ? null : $trimmed;
    }
}
