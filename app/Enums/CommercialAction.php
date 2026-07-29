<?php

namespace App\Enums;

/**
 * Commercial workflow actions gated by {@see CommercialState} (BR-04).
 */
enum CommercialAction: string
{
    case AssignServiceReference = 'assign_service_reference';
    case PaidService = 'paid_service';
    case PaidAppointment = 'paid_appointment';
    case ChargeCustomer = 'charge_customer';

    public function label(): string
    {
        return match ($this) {
            self::AssignServiceReference => 'Assign Service Reference',
            self::PaidService => 'Paid Service',
            self::PaidAppointment => 'Paid Appointment',
            self::ChargeCustomer => 'Charge Customer',
        };
    }
}
