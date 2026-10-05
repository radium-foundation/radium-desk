<?php

namespace App\Services\Finance;

use App\Enums\ApprovedRefundMethod;
use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceAccount;
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
        if ($refundAccount === null) {
            Log::warning('[Finance] Skipping refund journal — default refund account missing.', [
                'refund_id' => $refund->id,
            ]);

            return null;
        }

        try {
            $creditAccount = $this->resolveCreditAccount($refund);

            return $this->journals->post(
                sourceType: FinanceJournalSourceType::Refund,
                sourceId: $refund->id,
                idempotencyKey: 'refund:'.$refund->id,
                memo: $this->memoFor($refund),
                entryDate: $entryDate,
                lines: [
                    JournalLineDraft::debit($refundAccount->id, $amount, 'Customer refund'),
                    JournalLineDraft::credit($creditAccount->id, $amount, $this->creditLineDescription($refund)),
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

    /**
     * @throws ValidationException
     */
    private function resolveCreditAccount(RefundRequest $refund): FinanceAccount
    {
        $method = $refund->approved_refund_method;
        if (! $method instanceof ApprovedRefundMethod) {
            throw ValidationException::withMessages([
                'refund' => 'Approved refund method is required before a refund journal can be posted.',
            ]);
        }

        if ($method->isWalletCredit()) {
            return $this->settings->requireWalletLiabilityAccountForRefund();
        }

        if ($method->isExternalPaymentReversal()) {
            return $this->settings->requireBankClearingAccountForRefund();
        }

        throw ValidationException::withMessages([
            'refund' => 'Refund method ['.$method->value.'] cannot be classified for journal posting.',
        ]);
    }

    private function memoFor(RefundRequest $refund): string
    {
        $memo = 'Refund '.$refund->reference_no;
        $executionReference = trim((string) ($refund->execution_reference_no ?? ''));
        if ($executionReference !== '') {
            $memo .= ' ('.$executionReference.')';
        }

        return $memo;
    }

    private function creditLineDescription(RefundRequest $refund): string
    {
        $method = $refund->approved_refund_method;
        if ($method instanceof ApprovedRefundMethod && $method->isWalletCredit()) {
            return 'Wallet liability';
        }

        return 'Refund clearing';
    }
}
