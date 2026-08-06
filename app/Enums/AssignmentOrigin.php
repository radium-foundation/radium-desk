<?php

namespace App\Enums;

enum AssignmentOrigin: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Support = 'support';
    case AppointmentSmartAssignment = 'appointment_smart_assignment';
    case Refund = 'refund';
    case Sales = 'sales';

    public function isAutomatic(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Human / workflow ownership that Ready Queue must not overwrite.
     * Supervisor override, manual override, and explicit transfer remain allowed.
     */
    public function protectsReadyQueueOwnership(): bool
    {
        return match ($this) {
            self::Support,
            self::AppointmentSmartAssignment,
            self::Refund,
            self::Sales,
            self::Manual => true,
            self::Auto => false,
        };
    }
}
