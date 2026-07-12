<?php

namespace App\Enums;

enum CommunicationActionKey: string
{
    case DriverInstallationGuide = 'driver_installation_guide';
    case ReviewRequest = 'review_request';
    case RefundConfirmation = 'refund_confirmation';
    case BuyRdService = 'buy_rd_service';
    case BuyProduct = 'buy_product';

    public function label(): string
    {
        return match ($this) {
            self::DriverInstallationGuide => 'Driver Installation Guide',
            self::ReviewRequest => 'Review Request',
            self::RefundConfirmation => 'Refund Confirmation',
            self::BuyRdService => 'Buy RD Service',
            self::BuyProduct => 'Buy Product',
        };
    }
}
