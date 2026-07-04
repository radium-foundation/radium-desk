<?php

namespace App\Enums;

enum NewContactIntent: string
{
    case BuyDevice = 'buy_device';
    case ExistingDeviceService = 'existing_device_service';
    case GeneralSupport = 'general_support';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BuyDevice => 'Buy Device',
            self::ExistingDeviceService => 'Existing Device Service',
            self::GeneralSupport => 'General Support',
            self::Other => 'Other',
        };
    }

    public function incidentCategory(): string
    {
        return match ($this) {
            self::BuyDevice => 'Sales Lead',
            self::ExistingDeviceService => 'Service',
            self::GeneralSupport => 'General Support',
            self::Other => 'Manual Review',
        };
    }

    public function requiresSerial(): bool
    {
        return $this === self::ExistingDeviceService;
    }

    public function requiresProduct(): bool
    {
        return $this === self::ExistingDeviceService;
    }
}
