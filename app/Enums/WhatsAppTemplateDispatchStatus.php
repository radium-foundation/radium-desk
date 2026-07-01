<?php

namespace App\Enums;

enum WhatsAppTemplateDispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function timelineStatusLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Failed => 'Failed',
        };
    }

    public function statusVariant(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Sent => 'sent',
            self::Failed => 'failed',
        };
    }
}
