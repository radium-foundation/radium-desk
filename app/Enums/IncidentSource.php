<?php

namespace App\Enums;

enum IncidentSource: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Telegram = 'telegram';
    case Internal = 'internal';
    case Cashfree = 'cashfree';
    case Other = 'other';

    public static function fromIntakeKey(string $key): self
    {
        if ($resolved = self::tryFrom($key)) {
            return $resolved;
        }

        return match ($key) {
            'admin' => self::Internal,
            default => self::from($key),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Call',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Telegram => 'Telegram',
            self::Internal => 'Internal',
            self::Cashfree => 'Cashfree',
            self::Other => 'Other',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Call => 'bi-telephone-fill',
            self::WhatsApp => 'bi-whatsapp',
            self::Email => 'bi-envelope-fill',
            self::Telegram => 'bi-telegram',
            self::Internal => 'bi-building',
            self::Cashfree => 'bi-credit-card',
            self::Other => 'bi-three-dots',
        };
    }
}
