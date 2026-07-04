<?php

namespace App\Enums;

enum NotificationLinkSource: string
{
    case WhatsApp = 'whatsapp';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
        };
    }
}
