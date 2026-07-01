<?php

namespace App\Enums;

enum WhatsAppTemplateTriggerSource: string
{
    case Manual = 'manual';
    case Automation = 'automation';
    case Ira = 'ira';
    case Scheduler = 'scheduler';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Automation => 'Automation',
            self::Ira => 'IRA',
            self::Scheduler => 'Scheduler',
            self::Webhook => 'Webhook',
        };
    }
}
