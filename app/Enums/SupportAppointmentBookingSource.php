<?php

namespace App\Enums;

enum SupportAppointmentBookingSource: string
{
    case Web = 'web';
    case WhatsAppFlow = 'whatsapp_flow';

    public function notificationSource(): string
    {
        return match ($this) {
            self::Web => 'support_appointment_web',
            self::WhatsAppFlow => 'support_appointment_whatsapp_flow',
        };
    }

    public function whatsAppTriggerSource(): WhatsAppTemplateTriggerSource
    {
        return match ($this) {
            self::Web => WhatsAppTemplateTriggerSource::Automation,
            self::WhatsAppFlow => WhatsAppTemplateTriggerSource::Webhook,
        };
    }
}
