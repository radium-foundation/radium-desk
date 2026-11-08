<?php

namespace App\Enums;

enum PosCustomerRequestedSerialExchangeStatus: string
{
    case AwaitingStatutory = 'awaiting_statutory';
    case Ready = 'ready';
    case Executed = 'executed';

    public function label(): string
    {
        return match ($this) {
            self::AwaitingStatutory => 'Awaiting statutory treatment approval',
            self::Ready => 'Ready for exchange execution',
            self::Executed => 'Executed',
        };
    }
}
