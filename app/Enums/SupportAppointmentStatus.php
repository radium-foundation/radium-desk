<?php

namespace App\Enums;

enum SupportAppointmentStatus: string
{
    case Scheduled = 'scheduled';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
