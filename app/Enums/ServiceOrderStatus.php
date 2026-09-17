<?php

namespace App\Enums;

enum ServiceOrderStatus: string
{
    case Open = 'open';
    case Invoiced = 'invoiced';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Invoiced => 'Invoiced',
            self::Cancelled => 'Cancelled',
        };
    }
}
