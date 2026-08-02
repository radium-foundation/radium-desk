<?php

namespace App\Services\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceJournal;
use App\Models\FinanceJournalLine;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    public function __construct(
        private readonly FinanceJournalReferenceService $referenceService,
        private readonly AccountBalanceReadModel $balances,
    ) {}

    /**
     * @param  list<JournalLineDraft>  $lines
     */
    public function post(
        FinanceJournalSourceType $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        string $memo,
        \DateTimeInterface|string $entryDate,
        array $lines,
        ?User $actor = null,
    ): FinanceJournal {
        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A journal must contain at least one line.',
            ]);
        }

        $existing = FinanceJournal::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->load(['lines.account', 'poster']);
        }

        $debits = 0.0;
        $credits = 0.0;
        $normalized = [];

        foreach ($lines as $index => $line) {
            if (! $line instanceof JournalLineDraft) {
                throw ValidationException::withMessages([
                    'lines' => 'Invalid journal line draft.',
                ]);
            }

            $debit = round($line->debit, 2);
            $credit = round($line->credit, 2);

            if ($debit < 0 || $credit < 0) {
                throw ValidationException::withMessages([
                    'lines' => 'Debit and credit amounts must be non-negative.',
                ]);
            }

            if (($debit > 0 && $credit > 0) || ($debit == 0.0 && $credit == 0.0)) {
                throw ValidationException::withMessages([
                    'lines' => 'Each line must have either a debit or a credit (not both, not neither).',
                ]);
            }

            $debits += $debit;
            $credits += $credit;
            $normalized[] = [
                'account_id' => $line->accountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => $line->description,
                'line_no' => $index + 1,
            ];
        }

        if (round($debits, 2) !== round($credits, 2)) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Journal is unbalanced. Debits %s do not equal credits %s.',
                    number_format($debits, 2, '.', ''),
                    number_format($credits, 2, '.', ''),
                ),
            ]);
        }

        return DB::transaction(function () use ($sourceType, $sourceId, $idempotencyKey, $memo, $entryDate, $normalized, $actor): FinanceJournal {
            $again = FinanceJournal::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($again !== null) {
                return $again->load(['lines.account', 'poster']);
            }

            $journal = FinanceJournal::query()->create([
                'journal_no' => $this->referenceService->generate(),
                'entry_date' => $entryDate,
                'memo' => $memo,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'posted_by' => $actor?->id,
                'posted_at' => now(),
            ]);

            foreach ($normalized as $row) {
                FinanceJournalLine::query()->create([
                    'journal_id' => $journal->id,
                    ...$row,
                ]);
            }

            $this->balances->invalidateMany(
                array_values(array_unique(array_column($normalized, 'account_id'))),
            );

            return $journal->load(['lines.account', 'poster']);
        });
    }
}
