<?php

namespace App\Enums;

enum PosCustomerType: string
{
    case B2c = 'b2c';
    case B2b = 'b2b';

    public function label(): string
    {
        return match ($this) {
            self::B2c => 'B2C',
            self::B2b => 'B2B',
        };
    }
}
