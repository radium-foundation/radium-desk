<?php

namespace App\Services\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;

final class WalletRefundRecoveryPresenter
{
    public function __construct(
        private readonly WalletRefundExistingCreditDetector $detector,
    ) {}

    /**
     * @return array{
     *     amount: string,
     *     currency: string,
     *     wallet_reference: string,
     *     ledger_entry_id: int,
     *     central_wallet_id: string,
     * }|null
     */
    public function preview(RefundRequest $refund): ?array
    {
        if ($refund->status !== RefundStatus::PendingExecution) {
            return null;
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            return null;
        }

        $detection = $this->detector->detect($refund);
        if (! $detection->isMatched()) {
            return null;
        }

        $entry = $detection->ledgerEntry();
        if ($entry === null) {
            return null;
        }

        $amount = WalletMoney::normalize($entry->amount) ?? (string) $entry->amount;

        return [
            'amount' => $amount,
            'currency' => strtoupper(trim((string) $entry->currency)),
            'wallet_reference' => (string) $detection->walletReference(),
            'ledger_entry_id' => (int) $entry->id,
            'central_wallet_id' => (string) $entry->central_wallet_id,
        ];
    }
}
