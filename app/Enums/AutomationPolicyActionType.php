<?php

namespace App\Enums;

enum AutomationPolicyActionType: string
{
    case WhatsAppTemplate = 'whatsapp_template';
    case NotifyTeam = 'notify_team';
    case AutoClose = 'auto_close';
    case Custom = 'custom';

    public static function tryFromConfig(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
