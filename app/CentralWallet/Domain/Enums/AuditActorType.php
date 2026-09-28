<?php

namespace App\CentralWallet\Domain\Enums;

enum AuditActorType: string
{
    case System = 'system';
    case Service = 'service';
    case Operator = 'operator';
    case Customer = 'customer';
}
