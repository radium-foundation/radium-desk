<?php

namespace App\Enums;

enum NotificationChannelType: string
{
    case Telegram = 'telegram';
    case Desktop = 'desktop';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Desktop => 'Desktop',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::InApp => 'In-app',
        };
    }
}
