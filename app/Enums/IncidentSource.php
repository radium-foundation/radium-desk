<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Telegram = 'telegram';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Telegram => 'Telegram',
            self::Internal => 'Internal',
        };
    }
}
