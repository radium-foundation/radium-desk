<?php

namespace App\Enums;

enum ServiceCaseReopenSource: string
{
    case CustomerEmail = 'customer_email';
    case CustomerWhatsApp = 'customer_whatsapp';
    case CustomerCall = 'customer_call';
    case Manual = 'manual';
    case InternalTransfer = 'internal_transfer';

    public function label(): string
    {
        return match ($this) {
            self::CustomerEmail => 'Customer Email',
            self::CustomerWhatsApp => 'Customer WhatsApp',
            self::CustomerCall => 'Customer Call',
            self::Manual => 'Manual',
            self::InternalTransfer => 'Internal Transfer',
        };
    }
}
