<?php

namespace App\Enums;

enum ServiceCaseCloseNotificationPreference: string
{
    case No = 'no';
    case SmartDelivery = 'smart_delivery';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::No => 'No',
            self::SmartDelivery => 'Smart Delivery (Recommended)',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Email',
            self::Both => 'Both',
        };
    }

    public function usesSmartDelivery(): bool
    {
        return $this === self::SmartDelivery;
    }

    /**
     * @return list<self>
     */
    public static function customerNotificationOptions(): array
    {
        return [self::No, self::SmartDelivery];
    }

    /**
     * @return list<self>
     */
    public static function customerNotRespondingOptions(): array
    {
        return [self::SmartDelivery];
    }

    /**
     * @return array{notify_whatsapp: bool, notify_email: bool, smart_delivery?: bool}
     */
    public function toLegacyNotifyFlags(): array
    {
        return match ($this) {
            self::No => ['notify_whatsapp' => false, 'notify_email' => false],
            self::SmartDelivery => [
                'notify_whatsapp' => false,
                'notify_email' => false,
                'smart_delivery' => true,
            ],
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
