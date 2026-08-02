<?php

namespace App\Services\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\Data\JournalLineDraft;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderPaymentJournalService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly FinanceSettingsService $settings,
    ) {}

    public function postForOrder(Order $order, ?User $actor = null): ?FinanceJournal
    {
        $amount = round((float) ($order->payment_amount ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $entryDate = $order->payment_date ?? $order->created_at ?? now();
        if (! $this->settings->shouldPostForDate($entryDate)) {
            return null;
        }

        $bank = $this->settings->defaultBankClearingAccount();
        $revenue = $this->settings->defaultRevenueAccount();

        if ($bank === null || $revenue === null) {
            Log::warning('[Finance] Skipping order payment journal — default accounts missing.', [
                'order_id' => $order->id,
            ]);

            return null;
        }

        try {
            return $this->journals->post(
                sourceType: FinanceJournalSourceType::OrderPayment,
                sourceId: $order->id,
                idempotencyKey: 'order_payment:'.$order->id,
                memo: 'Order payment '.$order->order_id,
                entryDate: $entryDate,
                lines: [
                    JournalLineDraft::debit($bank->id, $amount, 'Cashfree collection'),
                    JournalLineDraft::credit($revenue->id, $amount, 'Sales / RD income'),
                ],
                actor: $actor,
            );
        } catch (ValidationException $exception) {
            Log::error('[Finance] Failed to post order payment journal.', [
                'order_id' => $order->id,
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        }
    }
}
