<?php

namespace App\Enums;

enum NotificationChannelType: string
{
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    // Reserved for future channels: SMS, Telegram, Push.
}
