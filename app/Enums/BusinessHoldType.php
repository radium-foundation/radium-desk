<?php

namespace App\Enums;

enum BusinessHoldType: string
{
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund Hold',
        };
    }
}
