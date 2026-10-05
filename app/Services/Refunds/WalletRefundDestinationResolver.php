<?php

namespace App\Services\Refunds;

use App\Support\BusinessOrderId;
use Illuminate\Validation\ValidationException;

final class WalletRefundDestinationResolver
{
    public const RDSERVICE_IN = 'rdservice.in';

    public const RDSERVICE_NET = 'rdservice.net';

    public const RADIUMBOX_COM = 'radiumbox.com';

    public function owner(?string $orderId): ?string
    {
        return BusinessOrderId::owner($orderId);
    }

    public function isRdServiceIn(?string $orderId): bool
    {
        return $this->owner($orderId) === self::RDSERVICE_IN;
    }

    public function isRdServiceNet(?string $orderId): bool
    {
        return $this->owner($orderId) === self::RDSERVICE_NET;
    }

    public function isRadiumBox(?string $orderId): bool
    {
        return $this->owner($orderId) === self::RADIUMBOX_COM;
    }

    public function supportsAutomatedWalletCredit(?string $orderId): bool
    {
        if ($this->isRdServiceIn($orderId) || $this->isRadiumBox($orderId)) {
            return true;
        }

        return $this->isRdServiceNet($orderId) && $this->isRdServiceNetWalletRefundConfigured();
    }

    public function isRdServiceNetWalletRefundConfigured(): bool
    {
        if (! config('rdservice_net.wallet_refund_credit_enabled')) {
            return false;
        }

        $config = config('order_lookup.spokes.rdservice_net', []);

        return (bool) ($config['enabled'] ?? false)
            && trim((string) ($config['token'] ?? '')) !== ''
            && trim((string) ($config['base_url'] ?? '')) !== '';
    }

    public function unsupportedAutomatedWalletCreditMessage(?string $orderId): string
    {
        $owner = $this->owner($orderId) ?? 'this order source';

        return 'No automated wallet-credit destination is configured for '
            .$owner
            .' orders. Approve this refund using Cashfree or another supported payout method instead.';
    }

    public function assertWalletApprovalAllowed(?string $orderId): void
    {
        if (! $this->isRdServiceNet($orderId)) {
            return;
        }

        if ($this->isRdServiceNetWalletRefundConfigured()) {
            return;
        }

        throw ValidationException::withMessages([
            'approved_refund_method' => $this->unsupportedAutomatedWalletCreditMessage($orderId),
        ]);
    }
}
