<?php

namespace App\Enums;

enum WhatsAppConversationStatus: string
{
    case WaitingForCustomer = 'waiting_for_customer';
    case WaitingForAgent = 'waiting_for_agent';
    case Failed = 'failed';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::WaitingForCustomer => 'Waiting for Customer',
            self::WaitingForAgent => 'Waiting for Agent',
            self::Failed => 'Failed',
            self::Inactive => 'Inactive',
        };
    }

    public function statusVariant(): string
    {
        return match ($this) {
            self::WaitingForCustomer => 'pending',
            self::WaitingForAgent => 'sent',
            self::Failed => 'failed',
            self::Inactive => 'pending',
        };
    }
}
