<?php

namespace App\Enums;

enum ReturnExchangeReplacementCategory: string
{
    case Warranty = 'warranty';
    case CommercialReturn = 'commercial_return';
    case PaidUpgrade = 'paid_upgrade';
    case Goodwill = 'goodwill';
    case CustomerRequestedExchange = 'customer_requested_exchange';

    public function label(): string
    {
        return match ($this) {
            self::Warranty => 'Warranty replacement',
            self::CommercialReturn => 'Commercial return',
            self::PaidUpgrade => 'Paid upgrade / product exchange',
            self::Goodwill => 'Goodwill replacement',
            self::CustomerRequestedExchange => 'Customer-requested product / variant exchange',
        };
    }

    public function requiresGoodwillApproval(): bool
    {
        return $this === self::Goodwill;
    }

    public function mayRecordPriceDifference(): bool
    {
        return $this === self::PaidUpgrade || $this === self::CustomerRequestedExchange;
    }
}
