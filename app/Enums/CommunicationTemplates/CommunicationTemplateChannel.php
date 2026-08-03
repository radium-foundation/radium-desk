<?php

namespace App\Enums\CommunicationTemplates;

enum CommunicationTemplateChannel: string
{
    case Email = 'email';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case InternalNote = 'internal_note';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::InternalNote => 'Internal Note',
        };
    }

    public function isFuture(): bool
    {
        return $this !== self::Email;
    }
}
