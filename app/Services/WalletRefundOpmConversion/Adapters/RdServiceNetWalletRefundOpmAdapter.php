<?php

namespace App\Services\WalletRefundOpmConversion\Adapters;

use App\Enums\WalletRefundCreditSource;

final class RdServiceNetWalletRefundOpmAdapter extends RadiumBoxWalletRefundOpmAdapter
{
    public function siteCode(): string
    {
        return 'rdservice.net';
    }

    public function isEnabled(): bool
    {
        return (bool) config('wallet_refund_opm_conversion.sites.rdservice.net.enabled', false);
    }

    public function supportsCreditSource(WalletRefundCreditSource $source): bool
    {
        return $source === WalletRefundCreditSource::CentralWallet;
    }

    protected function spokeConfig(): array
    {
        return (array) config('order_lookup.spokes.rdservice_net', []);
    }
}
