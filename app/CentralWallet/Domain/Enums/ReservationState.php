<?php

namespace App\CentralWallet\Domain\Enums;

enum ReservationState: string
{
    case Active = 'active';
    case Committed = 'committed';
    case Released = 'released';
    case Expired = 'expired';
}
