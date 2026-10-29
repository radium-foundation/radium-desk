<?php

namespace App\Support\Refunds;

use App\Services\OrderLookup\SpokeOrderClientFactory;

/**
 * Maps a Desk business order id to the spoke order key Box wallet APIs resolve reliably.
 */
final class RadiumBoxWalletRefundSpokeOrderResolver
{
    public function __construct(
        private readonly SpokeOrderClientFactory $spokeClients,
    ) {}

    public function resolveWalletOrderId(string $deskOrderId): string
    {
        $normalized = trim($deskOrderId);
        if ($normalized === '') {
            return $deskOrderId;
        }

        if (! filter_var(config('radiumbox.wallet_refund.resolve_spoke_order_id', true), FILTER_VALIDATE_BOOLEAN)) {
            return $normalized;
        }

        $client = $this->spokeClients->make('radiumbox_com');
        if (! $client->isConfigured() || ! $client->isEligible($normalized)) {
            return $normalized;
        }

        $payload = $client->fetchPayload($normalized);
        if ($payload === null) {
            return $normalized;
        }

        $orderCode = trim((string) data_get($payload, 'data.correlation.ordercode', ''));
        if ($orderCode !== '') {
            return $orderCode;
        }

        $ordersId = data_get($payload, 'data.correlation.orders_id');
        if (is_numeric($ordersId) && (int) $ordersId > 0) {
            return 'RDE'.(int) $ordersId;
        }

        return $normalized;
    }
}
