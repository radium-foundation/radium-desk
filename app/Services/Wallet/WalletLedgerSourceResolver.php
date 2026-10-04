<?php

namespace App\Services\Wallet;

use App\Models\Incident;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Support\BusinessOrderId;

final class WalletLedgerSourceResolver
{
    public function resolveForIncident(Incident $incident): WalletLedgerSource
    {
        $incident->loadMissing('order');
        $orderId = trim((string) ($incident->order?->order_id ?? ''));

        if ($orderId === '') {
            return WalletLedgerSource::RadiumBoxLegacy;
        }

        $owner = BusinessOrderId::owner($orderId);

        if (in_array($owner, [
            WalletRefundDestinationResolver::RDSERVICE_NET,
            WalletRefundDestinationResolver::RDSERVICE_IN,
        ], true)) {
            return WalletLedgerSource::CentralWallet;
        }

        return WalletLedgerSource::RadiumBoxLegacy;
    }
}
