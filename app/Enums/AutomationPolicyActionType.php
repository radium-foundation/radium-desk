<?php

namespace App\Enums;

enum AutomationPolicyActionType: string
{
    case WhatsAppTemplate = 'whatsapp_template';
    case NotifyTeam = 'notify_team';
    case AutoClose = 'auto_close';
    case Custom = 'custom';
    case AppointmentReminderTelegram = 'appointment_reminder_telegram';

    public static function tryFromConfig(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
