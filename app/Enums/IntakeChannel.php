<?php

namespace App\Enums;

enum IntakeChannel: string
{
    case Ivr = 'ivr';
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Web = 'web';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Ivr => 'IVR',
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Web => 'Web Form',
            self::Api => 'API',
        };
    }
}
