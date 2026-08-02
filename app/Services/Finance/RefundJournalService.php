<?php

namespace App\Services\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceJournal;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RefundJournalService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly FinanceSettingsService $settings,
    ) {}

    public function postForRefund(RefundRequest $refund, ?User $actor = null): ?FinanceJournal
    {
        $amount = round((float) $refund->displayAmount(), 2);
        if ($amount <= 0) {
            return null;
        }

        $entryDate = $refund->executed_at ?? now();
        if (! $this->settings->shouldPostForDate($entryDate)) {
            return null;
        }

        $refundAccount = $this->settings->defaultRefundAccount();
        $clearing = $this->settings->defaultBankClearingAccount();

        if ($refundAccount === null || $clearing === null) {
            Log::warning('[Finance] Skipping refund journal — default accounts missing.', [
                'refund_id' => $refund->id,
            ]);

            return null;
        }

        try {
            return $this->journals->post(
                sourceType: FinanceJournalSourceType::Refund,
                sourceId: $refund->id,
                idempotencyKey: 'refund:'.$refund->id,
                memo: 'Refund '.$refund->reference_no,
                entryDate: $entryDate,
                lines: [
                    JournalLineDraft::debit($refundAccount->id, $amount, 'Customer refund'),
                    JournalLineDraft::credit($clearing->id, $amount, 'Refund clearing'),
                ],
                actor: $actor,
            );
        } catch (ValidationException $exception) {
            Log::error('[Finance] Failed to post refund journal.', [
                'refund_id' => $refund->id,
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        }
    }
}
