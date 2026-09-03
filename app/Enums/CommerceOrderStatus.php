<?php

namespace App\Enums;

enum CommerceOrderStatus: string
{
    case Received = 'received';
    case Validated = 'validated';
    case Eligible = 'eligible';
    case InvoicePending = 'invoice_pending';
    case Invoiced = 'invoiced';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Validated => 'Validated',
            self::Eligible => 'Eligible',
            self::InvoicePending => 'Invoice pending',
            self::Invoiced => 'Invoiced',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }
}
