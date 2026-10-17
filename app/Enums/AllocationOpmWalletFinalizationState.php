<?php

namespace App\Enums;

enum AllocationOpmWalletFinalizationState: string
{
    case NotSent = 'not_sent';
    case InFlight = 'in_flight';
    case Confirmed = 'confirmed';
    case Ambiguous = 'ambiguous';
    case Failed = 'failed';
}
