<?php

namespace App\Services\WalletRefundOpmConversion\Adapters;

use App\Models\RefundRequest;
use App\Models\WalletRefundOpmConversion;
/**
 * Same spoke contract as RadiumBox; only site config differs.
 */
final class RdServiceInWalletRefundOpmAdapter extends RadiumBoxWalletRefundOpmAdapter
{
    public function siteCode(): string
    {
        return 'rdservice.in';
    }

    public function isEnabled(): bool
    {
        return (bool) config('wallet_refund_opm_conversion.sites.rdservice.in.enabled', false);
    }

    /**
     * @return array<string, mixed>
     */
    protected function spokeConfig(): array
    {
        return (array) config('order_lookup.spokes.rdservice_in', []);
    }
}
