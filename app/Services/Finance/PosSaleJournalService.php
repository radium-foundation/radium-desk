<?php

namespace App\Services\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Enums\InventoryFinanceHandoffStatus;
use App\Models\FinanceAccount;
use App\Models\FinanceJournal;
use App\Models\InventorySale;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PosSaleJournalService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly FinanceSettingsService $settings,
    ) {}

    /**
     * Post the POS sale into the existing Finance ledger.
     *
     * When ledger posting is enabled, missing accounts fail the sale so inventory
     * is not left consumed without a finance record. When posting is disabled,
     * the sale is marked skipped. Idempotent on pos_sale:{sale_id}.
     */
    public function postForSale(InventorySale $sale, ?User $actor = null, bool $failClosed = true): ?FinanceJournal
    {
        if (in_array($sale->finance_handoff_status, [
            InventoryFinanceHandoffStatus::Posted,
            InventoryFinanceHandoffStatus::Skipped,
            InventoryFinanceHandoffStatus::Reversed,
        ], true) && $sale->finance_journal_id !== null) {
            return $sale->financeJournal;
        }

        if (in_array($sale->finance_handoff_status, [
            InventoryFinanceHandoffStatus::Posted,
            InventoryFinanceHandoffStatus::Reversed,
        ], true)) {
            return $sale->financeJournal;
        }

        $amount = round((float) $sale->total, 2);
        if ($amount <= 0) {
            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Skipped,
            ]);

            return null;
        }

        $entryDate = $sale->completed_at ?? $sale->created_at ?? now();
        if (! $this->settings->shouldPostForDate($entryDate)) {
            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Skipped,
            ]);

            return null;
        }

        $cashOrBank = $this->settlementAccount($sale);
        $revenue = $this->settings->defaultRevenueAccount();

        if ($cashOrBank === null || $revenue === null) {
            Log::warning('[Finance] POS sale journal accounts missing.', [
                'sale_id' => $sale->id,
            ]);

            if ($failClosed) {
                throw ValidationException::withMessages([
                    'finance' => 'Finance accounts are not configured. The sale was not completed and stock was not taken.',
                ]);
            }

            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Skipped,
            ]);

            return null;
        }

        $journal = $this->journals->post(
            sourceType: FinanceJournalSourceType::PosSale,
            sourceId: $sale->id,
            idempotencyKey: 'pos_sale:'.$sale->id,
            memo: 'POS sale '.$sale->sale_no,
            entryDate: $entryDate,
            lines: [
                JournalLineDraft::debit($cashOrBank->id, $amount, 'POS collection '.$sale->payment_method),
                JournalLineDraft::credit($revenue->id, $amount, 'Retail sale '.$sale->invoice_number),
            ],
            actor: $actor ?? $sale->createdBy,
        );

        $sale->update([
            'finance_handoff_status' => InventoryFinanceHandoffStatus::Posted,
            'finance_journal_id' => $journal->id,
        ]);

        return $journal;
    }

    /**
     * Post a reversing journal for a posted POS sale.
     *
     * Same append-only pattern as Cash Book: original journal stays; debit/credit
     * are swapped. Not a GST credit note. Idempotent on pos_sale:reverse:{sale}:{journal}.
     * When the original handoff was skipped, this is a no-op.
     */
    public function reverseForSale(InventorySale $sale, User $actor, bool $failClosed = true): ?FinanceJournal
    {
        if ($sale->finance_handoff_status === InventoryFinanceHandoffStatus::Skipped) {
            return null;
        }

        if ($sale->finance_handoff_status !== InventoryFinanceHandoffStatus::Posted
            && $sale->finance_handoff_status !== InventoryFinanceHandoffStatus::Reversed) {
            return null;
        }

        $journal = FinanceJournal::query()
            ->with('lines')
            ->find($sale->finance_journal_id);

        if ($journal === null || $journal->lines->isEmpty()) {
            Log::warning('[Finance] POS sale reverse journal missing original.', [
                'sale_id' => $sale->id,
                'finance_journal_id' => $sale->finance_journal_id,
            ]);

            if ($failClosed && $sale->finance_handoff_status === InventoryFinanceHandoffStatus::Posted) {
                throw ValidationException::withMessages([
                    'finance' => 'The original POS journal is missing. The cancel was not completed and stock was not restored.',
                ]);
            }

            return null;
        }

        $lines = [];
        foreach ($journal->lines as $line) {
            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);

            if ($debit > 0) {
                $lines[] = JournalLineDraft::credit((int) $line->account_id, $debit, 'POS sale reversal');
            } elseif ($credit > 0) {
                $lines[] = JournalLineDraft::debit((int) $line->account_id, $credit, 'POS sale reversal');
            }
        }

        if ($lines === []) {
            if ($failClosed && $sale->finance_handoff_status === InventoryFinanceHandoffStatus::Posted) {
                throw ValidationException::withMessages([
                    'finance' => 'The original POS journal has no lines to reverse. The cancel was not completed and stock was not restored.',
                ]);
            }

            return null;
        }

        $reverse = $this->journals->post(
            sourceType: FinanceJournalSourceType::PosSale,
            sourceId: $sale->id,
            idempotencyKey: 'pos_sale:reverse:'.$sale->id.':'.$journal->id,
            memo: 'Reversal of POS sale '.$sale->sale_no,
            entryDate: now(),
            lines: $lines,
            actor: $actor,
        );

        if ($sale->finance_handoff_status !== InventoryFinanceHandoffStatus::Reversed) {
            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Reversed,
            ]);
        }

        return $reverse;
    }

    private function settlementAccount(InventorySale $sale): ?FinanceAccount
    {
        $method = strtolower((string) $sale->payment_method);
        if (str_contains($method, 'cash')) {
            return $this->settings->defaultCashAccount() ?? $this->settings->defaultBankClearingAccount();
        }

        return $this->settings->defaultBankClearingAccount() ?? $this->settings->defaultCashAccount();
    }
}
