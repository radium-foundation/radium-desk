<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\RefundExecutor;
use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxWalletRefundClient;
use Illuminate\Validation\ValidationException;

class WalletRefundExecutor implements RefundExecutor
{
    public function __construct(
        private readonly RadiumBoxWalletRefundClient $walletRefundClient,
        private readonly ManualRefundExecutor $manualRefundExecutor,
    ) {}

    public function supports(ApprovedRefundMethod $method): bool
    {
        return $method === ApprovedRefundMethod::Wallet;
    }

    public function execute(RefundRequest $refund, User $actor, array $payload): array
    {
        if (! $this->walletRefundClient->isConfigured()) {
            return $this->manualRefundExecutor->execute($refund, $actor, $payload);
        }

        $refund->loadMissing('order');

        $orderId = $refund->order?->order_id;
        if (! is_string($orderId) || trim($orderId) === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required before wallet credit can be posted.',
            ]);
        }

        $amount = (float) ($refund->refund_amount ?? $refund->amount ?? 0);
        $credit = $this->walletRefundClient->creditWalletRefund(
            deskRefundReference: (string) $refund->reference_no,
            orderId: trim($orderId),
            amount: $amount,
            customerEmail: $refund->order?->customer_email,
        );

        $remarks = trim((string) ($payload['remarks'] ?? ''));
        $manualNote = $remarks !== '' ? $remarks : 'Wallet credited automatically via RadiumBox integration.';

        return [
            'provider' => 'radiumbox_wallet',
            'reference_number' => $credit['wallet_reference'],
            'transaction_id' => (string) $credit['wallet_transaction_id'],
            'remarks' => $manualNote,
            'metadata' => [
                'method' => $refund->approved_refund_method?->value,
                'executed_by' => $actor->id,
                'desk_refund_reference' => $refund->reference_no,
                'wallet_balance' => $credit['balance'],
            ],
        ];
    }
}
