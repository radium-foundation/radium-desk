<?php

namespace App\Enums\CommunicationTemplates;

enum CommunicationTemplateCategory: string
{
    case Refund = 'refund';
    case Support = 'support';
    case Appointment = 'appointment';
    case Sales = 'sales';
    case Finance = 'finance';
    case General = 'general';
    case Personal = 'personal';
    case Global = 'global';
    case Internal = 'internal';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Future = 'future';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::Support => 'Support',
            self::Appointment => 'Appointment',
            self::Sales => 'Sales',
            self::Finance => 'Finance',
            self::General => 'General',
            self::Personal => 'Personal',
            self::Global => 'Global',
            self::Internal => 'Internal',
            self::WhatsApp => 'WhatsApp',
            self::Sms => 'SMS',
            self::Future => 'Future',
        };
    }

    public function isReplyPlaybookCategory(): bool
    {
        return match ($this) {
            self::Support,
            self::Refund,
            self::Appointment,
            self::Sales,
            self::General,
            self::Personal,
            self::Global => true,
            default => false,
        };
    }
}
