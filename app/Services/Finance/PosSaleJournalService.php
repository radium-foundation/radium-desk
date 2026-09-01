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
        ], true) && $sale->finance_journal_id !== null) {
            return $sale->financeJournal;
        }

        if ($sale->finance_handoff_status === InventoryFinanceHandoffStatus::Posted) {
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

    private function settlementAccount(InventorySale $sale): ?FinanceAccount
    {
        $method = strtolower((string) $sale->payment_method);
        if (str_contains($method, 'cash')) {
            return $this->settings->defaultCashAccount() ?? $this->settings->defaultBankClearingAccount();
        }

        return $this->settings->defaultBankClearingAccount() ?? $this->settings->defaultCashAccount();
    }
}
