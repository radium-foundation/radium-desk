<?php

namespace App\Enums;

/**
 * Collection mode sent to Shiprocket. Distinct from commerce payment_method
 * (UPI/card/etc) and from courier COD capability on serviceability rows.
 * Hardware fulfilment currently uses Prepaid only. COD is reserved for later.
 */
enum HardwareShipmentCollectionMode: string
{
    case Prepaid = 'prepaid';
    case Cod = 'cod';

    public function serviceabilityCod(): int
    {
        return $this === self::Cod ? 1 : 0;
    }

    public function providerPaymentMethod(): string
    {
        return match ($this) {
            self::Prepaid => 'Prepaid',
            self::Cod => 'COD',
        };
    }

    public function label(): string
    {
        return $this->providerPaymentMethod();
    }

    public function isEnabledForHardware(): bool
    {
        return $this === self::Prepaid;
    }
}
