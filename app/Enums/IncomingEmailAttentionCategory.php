<?php

namespace App\Enums;

enum IncomingEmailAttentionCategory: string
{
    case Sales = 'sales';
    case Orders = 'orders';
    case Priority = 'priority';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Orders => 'Orders',
            self::Priority => 'Escalations',
        };
    }
}
