<?php

namespace App\Enums;

enum AllocationOpmReservationState: string
{
    case NotSent = 'not_sent';
    case IntentRecorded = 'intent_recorded';
    case InFlight = 'in_flight';
    case Confirmed = 'confirmed';
    case Ambiguous = 'ambiguous';
    case Failed = 'failed';
}
