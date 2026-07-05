<?php

namespace App\Enums;

enum IraNotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
