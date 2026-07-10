<?php

namespace App\Enums;

enum NotificationType: string
{
    case RequestSerialNumber = 'request_serial_number';
    case RequestCorrectSerial = 'request_correct_serial';
    case CustomerWaitingFollowup = 'customer_waiting_followup';
    case CallbackSchedule = 'callback_schedule';
    case SupportAppointmentBooked = 'support_appointment_booked';
    case SupportAppointmentAssigned = 'support_appointment_assigned';
    case ServiceCaseClosed = 'service_case_closed';
}
