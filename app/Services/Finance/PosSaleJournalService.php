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

    public function postForSale(InventorySale $sale, ?User $actor = null): ?FinanceJournal
    {
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
            Log::warning('[Finance] Skipping POS sale journal — default accounts missing.', [
                'sale_id' => $sale->id,
            ]);
            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Skipped,
            ]);

            return null;
        }

        try {
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
        } catch (ValidationException $exception) {
            Log::error('[Finance] Failed to post POS sale journal.', [
                'sale_id' => $sale->id,
                'errors' => $exception->errors(),
            ]);
            $sale->update([
                'finance_handoff_status' => InventoryFinanceHandoffStatus::Failed,
            ]);

            throw $exception;
        }
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
