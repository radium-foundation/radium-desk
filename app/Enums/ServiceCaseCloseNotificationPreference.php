<?php

namespace App\Enums;

enum ServiceCaseCloseNotificationPreference: string
{
    case No = 'no';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::No => 'No',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Both => 'Both',
        };
    }

    /**
     * @return array{notify_whatsapp: bool, notify_email: bool}
     */
    public function toLegacyNotifyFlags(): array
    {
        return match ($this) {
            self::No => ['notify_whatsapp' => false, 'notify_email' => false],
            self::WhatsApp => ['notify_whatsapp' => true, 'notify_email' => false],
            self::Email => ['notify_whatsapp' => false, 'notify_email' => true],
            self::Both => ['notify_whatsapp' => true, 'notify_email' => true],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
