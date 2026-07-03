<?php

namespace App\Enums;

enum WhatsAppTemplate: string
{
    case RequestSerialNumber = 'request_serial_number';
    case RepairStarted = 'repair_started';
    case RepairCompleted = 'repair_completed';
    case ReadyForDispatch = 'ready_for_dispatch';
    case RefundUpdate = 'refund_update';
    case AmcReminder = 'amc_reminder';
    case SupportAppointmentBooked = 'support_appointment_booked';

    public function purposeLabel(): string
    {
        return match ($this) {
            self::RequestSerialNumber => 'Request Serial Number',
            self::RepairStarted => 'Repair Started',
            self::RepairCompleted => 'Repair Completed',
            self::ReadyForDispatch => 'Ready for Dispatch',
            self::RefundUpdate => 'Refund Update',
            self::AmcReminder => 'AMC Reminder',
            self::SupportAppointmentBooked => 'Support Appointment Booked',
        };
    }
}
