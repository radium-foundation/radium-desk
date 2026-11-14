<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\RadiumBoxPaymentConfirmationResult;
use App\Models\Order;
use App\Services\RadiumBox\Contracts\RadiumBoxPaymentDownstreamGateway;

/**
 * Legacy contract: Desk asks Box to call Cashfree (provider verification on spoke).
 *
 * @deprecated Remove after radiumbox.com implements desk_authoritative_payment_v1.
 */
final class LegacyRadiumBoxCashfreeDelegationGateway implements RadiumBoxPaymentDownstreamGateway
{
    public const CONTRACT = 'legacy_cashfree_via_box';

    public function __construct(
        private readonly RadiumBoxPaymentConfirmationClient $client,
    ) {}

    public function contractName(): string
    {
        return self::CONTRACT;
    }

    public function notifyPaid(Order $order, bool $dryRun = false): RadiumBoxPaymentConfirmationResult
    {
        $gatewayOrderId = trim((string) $order->order_id);
        $paymentId = trim((string) ($order->cashfree_payment_id ?? ''));
        $paymentId = $paymentId !== '' ? $paymentId : null;

        return $this->client->confirmPayment($gatewayOrderId, $paymentId, $dryRun);
    }
}