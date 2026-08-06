<?php

namespace App\Enums;

enum IraMemorySource: string
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Refund = 'refund';
    case ServiceCase = 'service_case';
    case Appointment = 'appointment';
    case SystemRule = 'system_rule';
    case CallNotes = 'call_notes';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Whatsapp => 'WhatsApp',
            self::Refund => 'Refund',
            self::ServiceCase => 'Service Case',
            self::Appointment => 'Appointment',
            self::SystemRule => 'System Rule',
            self::CallNotes => 'Call Notes',
        };
    }
}
