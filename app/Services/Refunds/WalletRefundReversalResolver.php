<?php

namespace App\Services\Refunds;

use App\Contracts\Refunds\WalletRefundReversalClient;
use App\Models\RefundRequest;
use App\Services\RadiumBox\RadiumBoxWalletRefundReversalClient;
use App\Services\RdService\RdServiceInWalletRefundReversalClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

class WalletRefundReversalResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly WalletRefundDestinationResolver $destinations,
    ) {}

    public function forOrderId(?string $orderId): WalletRefundReversalClient
    {
        if ($this->destinations->isRdServiceIn($orderId)) {
            return $this->container->make(RdServiceInWalletRefundReversalClient::class);
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return $this->container->make(RadiumBoxWalletRefundReversalClient::class);
        }

        throw ValidationException::withMessages([
            'refund' => 'No wallet reversal destination exists for this order source.',
        ]);
    }

    public function reverse(
        RefundRequest $refund,
        string $idempotencyKey,
    ): array {
        $refund->loadMissing('order');

        $orderId = $refund->order?->order_id;
        if (! is_string($orderId) || trim($orderId) === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required before wallet reversal can be posted.',
            ]);
        }

        $amount = \App\Support\Money\WalletMoney::normalize($refund->refund_amount ?? $refund->amount);
        if ($amount === null || ! \App\Support\Money\WalletMoney::isPositive($amount)) {
            throw ValidationException::withMessages([
                'refund' => 'A positive refund amount is required before wallet reversal can be posted.',
            ]);
        }

        return $this->forOrderId(trim($orderId))->reverseWalletRefund(
            refund: $refund,
            orderId: trim($orderId),
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            customerEmail: $refund->order?->customer_email,
        );
    }
}
