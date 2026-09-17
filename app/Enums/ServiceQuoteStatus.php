<?php

namespace App\Enums;

enum ServiceQuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Accepted => 'Accepted',
            self::Converted => 'Converted',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }
}
