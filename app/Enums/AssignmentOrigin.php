<?php

namespace App\Enums;

enum AssignmentOrigin: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case AppointmentSmartAssignment = 'appointment_smart_assignment';

    public function isAutomatic(): bool
    {
        return $this === self::Auto || $this === self::AppointmentSmartAssignment;
    }
}
