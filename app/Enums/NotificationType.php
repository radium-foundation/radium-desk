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
    case DriverInstallationGuide = 'driver_installation_guide';
    case ReviewRequest = 'review_request';
    case RefundConfirmation = 'refund_confirmation';
    case BuyRdService = 'buy_rd_service';
    case BuyProduct = 'buy_product';
}
