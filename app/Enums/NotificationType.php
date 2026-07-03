<?php

namespace App\Enums;

enum NotificationType: string
{
    case RequestSerialNumber = 'request_serial_number';
    case SupportAppointmentBooked = 'support_appointment_booked';
}
