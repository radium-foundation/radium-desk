<?php

namespace App\Enums\AI;

enum CustomerJourneyMilestoneType: string
{
    case PaymentReceived = 'payment_received';
    case OrderImported = 'order_imported';
    case DeviceIdentified = 'device_identified';
    case SerialVerified = 'serial_verified';
    case SerialCorrectionRequested = 'serial_correction_requested';
    case CustomerReplied = 'customer_replied';
    case SupportAppointmentBooked = 'support_appointment_booked';
    case SupportCompleted = 'support_completed';
    case WaitingForCustomer = 'waiting_for_customer';
    case Reopened = 'reopened';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::PaymentReceived => 'Payment received',
            self::OrderImported => 'Order imported',
            self::DeviceIdentified => 'Device identified',
            self::SerialVerified => 'Serial verified',
            self::SerialCorrectionRequested => 'Serial verification requested',
            self::CustomerReplied => 'Customer replied',
            self::SupportAppointmentBooked => 'Customer booked support',
            self::SupportCompleted => 'Support completed',
            self::WaitingForCustomer => 'Waiting for customer',
            self::Reopened => 'Case reopened',
            self::Closed => 'Service case closed',
        };
    }
}
