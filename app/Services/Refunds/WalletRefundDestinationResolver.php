<?php

namespace App\Services\Refunds;

use App\Support\BusinessOrderId;

final class WalletRefundDestinationResolver
{
    public const RDSERVICE_IN = 'rdservice.in';

    public const RADIUMBOX_COM = 'radiumbox.com';

    public function owner(?string $orderId): ?string
    {
        return BusinessOrderId::owner($orderId);
    }

    public function isRdServiceIn(?string $orderId): bool
    {
        return $this->owner($orderId) === self::RDSERVICE_IN;
    }

    public function isRadiumBox(?string $orderId): bool
    {
        return $this->owner($orderId) === self::RADIUMBOX_COM;
    }
}
