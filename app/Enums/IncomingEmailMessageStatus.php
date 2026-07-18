<?php

namespace App\Enums;

enum IncomingEmailMessageStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Linked = 'linked';
    case HistoricalCustomer = 'historical_customer';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processing => 'Processing',
            self::Linked => 'Linked',
            self::HistoricalCustomer => 'Historical Customer - No Active Service Case',
            self::Ignored => 'Ignored',
            self::Failed => 'Failed',
        };
    }
}
