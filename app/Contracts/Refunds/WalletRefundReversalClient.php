<?php

namespace App\Contracts\Refunds;

use App\Models\RefundRequest;

interface WalletRefundReversalClient
{
    public function isConfigured(): bool;

    /**
     * @return array{
     *     wallet_reversal_reference: string,
     *     wallet_reversal_transaction_id: string,
     *     balance: string,
     * }
     */
    public function reverseWalletRefund(
        RefundRequest $refund,
        string $orderId,
        string $amount,
        string $idempotencyKey,
        ?string $customerEmail = null,
    ): array;
}
