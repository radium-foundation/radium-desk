<?php

namespace App\Enums;

enum NotificationChannelType: string
{
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Desktop = 'desktop';
    case Telegram = 'telegram';
}
