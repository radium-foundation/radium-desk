<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Models\RefundRequest;
use App\Services\Refunds\WalletRefundDestinationResolver;
use App\Services\WalletRefundOpmConversion\Adapters\RadiumBoxWalletRefundOpmAdapter;
use App\Services\WalletRefundOpmConversion\Adapters\RdServiceInWalletRefundOpmAdapter;
use App\Services\WalletRefundOpmConversion\Adapters\RdServiceNetWalletRefundOpmAdapter;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSiteAdapter;
use Illuminate\Validation\ValidationException;

final class WalletRefundOpmSiteAdapterResolver
{
    public function __construct(
        private readonly WalletRefundDestinationResolver $destinations,
        private readonly WalletRefundCreditSourceResolver $creditSources,
        private readonly RadiumBoxWalletRefundOpmAdapter $radiumBox,
        private readonly RdServiceInWalletRefundOpmAdapter $rdServiceIn,
        private readonly RdServiceNetWalletRefundOpmAdapter $rdServiceNet,
    ) {}

    public function forRefund(RefundRequest $refund): WalletRefundOpmSiteAdapter
    {
        $refund->loadMissing('order');
        $adapter = $this->forOrderId($refund->order?->order_id);
        $source = $this->creditSources->resolve($refund);

        if (! $adapter->supportsCreditSource($source)) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet-to-OPM conversion is not supported for '
                    .$source->label()
                    .' credits on '
                    .$adapter->siteCode()
                    .'.',
            ]);
        }

        return $adapter;
    }

    public function forOrderId(?string $orderId): WalletRefundOpmSiteAdapter
    {
        if (! is_string($orderId) || trim($orderId) === '') {
            throw ValidationException::withMessages([
                'refund' => 'Refund order id is required to resolve the wallet OPM conversion site.',
            ]);
        }

        $orderId = trim($orderId);

        if ($this->destinations->isRdServiceIn($orderId)) {
            return $this->rdServiceIn;
        }

        if ($this->destinations->isRdServiceNet($orderId)) {
            return $this->rdServiceNet;
        }

        if ($this->destinations->isRadiumBox($orderId)) {
            return $this->radiumBox;
        }

        throw ValidationException::withMessages([
            'refund' => 'No wallet OPM conversion site adapter exists for this order source.',
        ]);
    }
}
