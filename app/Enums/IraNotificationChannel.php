<?php

namespace App\Enums;

enum IraNotificationChannel: string
{
    case Telegram = 'telegram';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
        };
    }
}
