<?php

namespace App\CentralWallet\Application;

use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use App\Services\OrderIdentityProtectionService;

/**
 * Single post-payment entry point for Cashfree → Desk customer_id binding.
 * Delegates identity rules to {@see CashfreeCentralCustomerBinder}.
 */
final class CashfreeOrderCentralCustomerBindingService
{
    public function __construct(
        private readonly CashfreeCentralCustomerBinder $binder,
        private readonly OrderIdentityProtectionService $identityProtection,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {}

    /**
     * Idempotent bind for a Cashfree-paid order inside the caller's DB transaction when possible.
     *
     * @param  array<string, mixed>|null  $cashfreePayload
     */
    public function bindPaidOrder(
        Order $order,
        string $cfPaymentId,
        ?array $cashfreePayload = null,
        ?User $actor = null,
    ): string {
        if ($cashfreePayload !== null && $actor !== null) {
            $this->maybeApplyCashfreeEmailForBinding($order, $cashfreePayload, $actor);
        }

        $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first() ?? $order;

        return $this->binder->bindOrder(
            order: $fresh,
            cfPaymentId: $cfPaymentId,
            correlationId: $this->correlationId($cfPaymentId),
        );
    }

    public function correlationId(string $cfPaymentId): string
    {
        return 'cashfree:'.$cfPaymentId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function maybeApplyCashfreeEmailForBinding(Order $order, array $payload, User $actor): void
    {
        if (trim((string) ($order->customer_email ?? '')) !== '') {
            return;
        }

        if ($this->identityProtection->isFieldLocked($order, 'customer_email')) {
            return;
        }

        $email = $this->payloadParser->customerEmail($payload);
        $updates = $this->identityProtection->buildExternalIdentityUpdates($order, [
            'customer_email' => $email,
        ]);

        if ($updates === []) {
            return;
        }

        $order->update([
            ...$updates,
            'updated_by' => $actor->id,
        ]);
    }
}
